<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\IdempotencyKey;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BookingService
{
    public function __construct(private readonly InventoryService $inventoryService) {}

    /** @param array{hotel_id:int,room_type_id:int,check_in_date:string,check_out_date:string,rooms_count:int,customer_name:string,customer_contact:string} $data
     * @return array{status:int,body:array<string,mixed>,replayed:bool}
     */
    public function create(User $user, array $data, string $key): array
    {
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $existing = IdempotencyKey::query()->where('user_id', $user->id)->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            return $this->replayOrConflict($existing, $hash);
        }

        try {
            return DB::transaction(function () use ($user, $data, $key, $hash): array {
                $idempotency = IdempotencyKey::query()->create([
                    'user_id' => $user->id,
                    'idempotency_key' => $key,
                    'request_hash' => $hash,
                ]);

                $roomType = RoomType::query()
                    ->whereKey($data['room_type_id'])
                    ->where('hotel_id', $data['hotel_id'])
                    ->firstOrFail();

                $nights = $this->inventoryService->reserve(
                    $roomType,
                    Carbon::parse($data['check_in_date']),
                    Carbon::parse($data['check_out_date']),
                    $data['rooms_count'],
                );

                $booking = $this->createWithUniqueNumber($user, $data);
                foreach ($nights as $night) {
                    $booking->items()->create(['inventory_id' => $night->id, 'rooms_count' => $data['rooms_count']]);
                }

                $booking->load('items.inventory');
                $body = ['data' => $booking->toArray()];
                $idempotency->update(['response_status' => 201, 'response_body' => $body]);

                return ['status' => 201, 'body' => $body, 'replayed' => false];
            }, attempts: 3);
        } catch (QueryException $exception) {
            $existing = IdempotencyKey::query()->where('user_id', $user->id)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $this->replayOrConflict($existing, $hash);
            }

            throw $exception;
        }
    }

    public function cancel(User $user, int $bookingId): Booking
    {
        return DB::transaction(function () use ($user, $bookingId): Booking {
            $booking = Booking::query()->where('user_id', $user->id)->whereKey($bookingId)->lockForUpdate()->firstOrFail();
            if ($booking->status === 'cancelled') {
                return $booking;
            }

            $this->inventoryService->restoreFor($booking);
            $booking->update(['status' => 'cancelled']);

            return $booking->fresh('items.inventory');
        }, attempts: 3);
    }

    /** @param array<string,mixed> $data */
    private function createWithUniqueNumber(User $user, array $data): Booking
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return Booking::query()->create([
                    'user_id' => $user->id,
                    'booking_number' => 'AFP-'.now()->format('Ymd').'-'.$this->randomBookingSuffix(),
                    'status' => 'confirmed',
                    'customer_name' => $data['customer_name'],
                    'customer_contact' => $data['customer_contact'],
                ]);
            } catch (QueryException $exception) {
                if (! str_contains(strtolower($exception->getMessage()), 'booking_number')) {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException('Could not allocate a unique booking number after five attempts.');
    }

    private function randomBookingSuffix(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $suffix = '';
        for ($index = 0; $index < 6; $index++) {
            $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $suffix;
    }

    /** @return array{status:int,body:array<string,mixed>,replayed:bool} */
    private function replayOrConflict(IdempotencyKey $existing, string $hash): array
    {
        if (! hash_equals($existing->request_hash, $hash)) {
            throw new ConflictHttpException('Idempotency-Key was already used with a different request.');
        }

        if ($existing->response_status === null || $existing->response_body === null) {
            throw new ConflictHttpException('A request with this Idempotency-Key is still being processed. Retry shortly.');
        }

        return ['status' => $existing->response_status, 'body' => $existing->response_body, 'replayed' => true];
    }
}
