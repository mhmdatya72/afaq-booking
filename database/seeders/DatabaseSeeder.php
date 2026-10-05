<?php

namespace Database\Seeders;

use App\Models\Hotel;
use App\Models\Inventory;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => 'password'],
        );

        $hotel = Hotel::query()->firstOrCreate(['name' => 'Afaq Sample Hotel'], ['timezone' => 'Africa/Cairo']);
        $roomType = RoomType::query()->firstOrCreate(['hotel_id' => $hotel->id, 'name' => 'Standard']);
        for ($offset = 0; $offset < 90; $offset++) {
            Inventory::query()->updateOrCreate(
                ['room_type_id' => $roomType->id, 'inventory_date' => Carbon::today()->addDays($offset)->toDateString()],
                ['total_rooms' => 5, 'available_rooms' => 5],
            );
        }
    }
}
