<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Inventory;
use App\Models\RoomType;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class InventoryService
{
    /** @return list<Inventory> */
    public function reserve(RoomType $roomType, Carbon $checkIn, Carbon $checkOut, int $roomsCount): array
    {
        $nights = [];
        for ($date = $checkIn->copy(); $date->lt($checkOut); $date->addDay()) {
            $nights[] = $date->toDateString();
        }

        $rows = Inventory::query()
            ->where('room_type_id', $roomType->id)
            ->whereBetween('inventory_date', [
                $checkIn->toDateString().' 00:00:00',
                $checkOut->copy()->subDay()->toDateString().' 23:59:59.999999',
            ])
            ->orderBy('inventory_date')
            ->lockForUpdate()
            ->get();

        if ($rows->count() !== count($nights) || $rows->contains(fn (Inventory $item): bool => $item->available_rooms < $roomsCount)) {
            throw new ConflictHttpException('Insufficient availability for one or more nights.');
        }

        foreach ($rows as $row) {
            $affected = Inventory::query()
                ->whereKey($row->id)
                ->where('available_rooms', '>=', $roomsCount)
                ->decrement('available_rooms', $roomsCount);

            if ($affected !== 1) {
                throw new ConflictHttpException('Inventory changed while the booking was being processed.');
            }
        }

        return $rows->all();
    }

    public function restoreFor(Booking $booking): void
    {
        $items = $booking->items()->orderBy('inventory_id')->get();
        $inventory = Inventory::query()
            ->whereIn('id', $items->pluck('inventory_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($items as $item) {
            $row = $inventory->get($item->inventory_id);
            if ($row === null) {
                throw new \LogicException('Booking inventory row is missing; cancellation cannot safely restore it.');
            }

            $affected = Inventory::query()
                ->whereKey($row->id)
                ->where('available_rooms', '<=', $row->total_rooms - $item->rooms_count)
                ->increment('available_rooms', $item->rooms_count);

            if ($affected !== 1) {
                throw new \LogicException('Cancellation would exceed the inventory row total room capacity.');
            }
        }
    }
}
