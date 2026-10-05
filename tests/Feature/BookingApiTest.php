<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Hotel;
use App\Models\Inventory;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BookingApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Hotel $hotel;

    private RoomType $roomType;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 12:00:00');
        $this->user = User::factory()->create();
        $this->hotel = Hotel::query()->create(['name' => 'Test Hotel']);
        $this->roomType = RoomType::query()->create(['hotel_id' => $this->hotel->id, 'name' => 'Queen']);
        foreach (['2026-10-10', '2026-10-11', '2026-10-12'] as $date) {
            Inventory::query()->create(['room_type_id' => $this->roomType->id, 'inventory_date' => $date, 'total_rooms' => 5, 'available_rooms' => 5]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_successful_booking_reserves_each_night_and_excludes_checkout(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/bookings', $this->payload(), $this->headers())
            ->assertCreated()->assertJsonPath('data.status', 'confirmed');

        $this->assertMatchesRegularExpression('/^AFP-20261005-[A-Z0-9]{6}$/', $response->json('data.booking_number'));
        $this->assertSame(3, Inventory::query()->whereDate('inventory_date', '2026-10-10')->value('available_rooms'));
        $this->assertSame(3, Inventory::query()->whereDate('inventory_date', '2026-10-11')->value('available_rooms'));
        $this->assertSame(5, Inventory::query()->whereDate('inventory_date', '2026-10-12')->value('available_rooms'));
    }

    public function test_booking_fails_when_any_night_has_no_availability_and_rolls_back_all_nights(): void
    {
        Inventory::query()->whereDate('inventory_date', '2026-10-11')->update(['available_rooms' => 0]);
        $this->actingAs($this->user)->postJson('/api/v1/bookings', $this->payload(), $this->headers())->assertConflict();
        $this->assertDatabaseCount('bookings', 0);
        $this->assertSame(5, Inventory::query()->whereDate('inventory_date', '2026-10-10')->value('available_rooms'));
    }

    public function test_idempotency_replays_same_response_and_rejects_changed_request(): void
    {
        $headers = $this->headers();
        $first = $this->actingAs($this->user)->postJson('/api/v1/bookings', $this->payload(), $headers)->assertCreated();
        $id = $first->json('data.id');
        $this->postJson('/api/v1/bookings', $this->payload(), $headers)
            ->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('bookings', 1);
        $this->assertSame(3, Inventory::query()->whereDate('inventory_date', '2026-10-10')->value('available_rooms'));

        $changed = $this->payload();
        $changed['rooms_count'] = 3;
        $this->postJson('/api/v1/bookings', $changed, $headers)->assertConflict();
    }

    public function test_cancellation_restores_inventory_once(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/bookings', $this->payload(), $this->headers())->assertCreated();
        $id = $response->json('data.id');
        $this->postJson("/api/v1/bookings/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/v1/bookings/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(5, Inventory::query()->whereDate('inventory_date', '2026-10-10')->value('available_rooms'));
    }

    public function test_competing_requests_cannot_overbook_the_last_available_rooms(): void
    {
        Inventory::query()->update(['available_rooms' => 2]);
        $this->actingAs($this->user)->postJson('/api/v1/bookings', $this->payload(), ['Idempotency-Key' => 'request-a'])->assertCreated();
        $this->postJson('/api/v1/bookings', $this->payload(), ['Idempotency-Key' => 'request-b'])->assertConflict();
        $this->assertSame(1, Booking::query()->count());
        $this->assertSame(0, Inventory::query()->whereDate('inventory_date', '2026-10-10')->value('available_rooms'));
    }

    public function test_authentication_and_owner_scoped_booking_access(): void
    {
        $this->postJson('/api/v1/bookings', $this->payload(), $this->headers())->assertUnauthorized();
        $response = $this->actingAs($this->user)->postJson('/api/v1/bookings', $this->payload(), $this->headers())->assertCreated();
        $otherUser = User::factory()->create();
        $this->actingAs($otherUser)->postJson('/api/v1/bookings/'.$response->json('data.id').'/cancel')->assertNotFound();
    }

    private function payload(): array
    {
        return [
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->roomType->id,
            'check_in_date' => '2026-10-10',
            'check_out_date' => '2026-10-12',
            'rooms_count' => 2,
            'customer_name' => 'Ada Lovelace',
            'customer_contact' => 'ada@example.test',
        ];
    }

    private function headers(): array
    {
        return ['Idempotency-Key' => 'test-booking-key'];
    }
}
