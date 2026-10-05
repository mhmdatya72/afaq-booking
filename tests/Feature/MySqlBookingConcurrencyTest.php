<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Hotel;
use App\Models\Inventory;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class MySqlBookingConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_simultaneous_bookings_cannot_reserve_the_same_last_room(): void
    {
        if (config('database.default') !== 'mysql' || ! function_exists('proc_open')) {
            $this->markTestSkipped('This concurrency test requires MySQL and proc_open.');
        }

        $user = User::factory()->create();
        $hotel = Hotel::query()->create(['name' => 'Concurrency Test Hotel']);
        $roomType = RoomType::query()->create(['hotel_id' => $hotel->id, 'name' => 'Single Room']);
        Inventory::query()->create([
            'room_type_id' => $roomType->id,
            'inventory_date' => '2030-05-10',
            'total_rooms' => 1,
            'available_rooms' => 1,
        ]);

        $payload = [
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'check_in_date' => '2030-05-10',
            'check_out_date' => '2030-05-11',
            'rooms_count' => 1,
            'customer_name' => 'Concurrency Test',
            'customer_contact' => 'concurrency@example.test',
        ];

        $barrierPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'booking-race-'.bin2hex(random_bytes(8));
        $workers = [];
        for ($worker = 0; $worker < 2; $worker++) {
            $readyPath = $barrierPath.'.'.$worker.'.ready';
            $command = [
                PHP_BINARY,
                base_path('tests/Support/concurrent_booking_worker.php'),
                (string) $user->id,
                base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
                'mysql-race-'.$worker,
                $barrierPath,
                $readyPath,
            ];
            $pipes = [];
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            if (! is_resource($process)) {
                $this->fail('Could not start an independent booking worker.');
            }

            fclose($pipes[0]);
            $workers[] = ['process' => $process, 'pipes' => $pipes, 'ready_path' => $readyPath];
        }

        try {
            $readyDeadline = microtime(true) + 20;
            while (count(array_filter($workers, fn (array $worker): bool => file_exists($worker['ready_path']))) !== count($workers)) {
                if (microtime(true) >= $readyDeadline) {
                    $this->fail('Booking workers did not reach the concurrency barrier in time.');
                }

                usleep(10_000);
            }

            file_put_contents($barrierPath, 'start');

            $results = [];
            foreach ($workers as $worker) {
                $stdout = stream_get_contents($worker['pipes'][1]);
                $stderr = stream_get_contents($worker['pipes'][2]);
                fclose($worker['pipes'][1]);
                fclose($worker['pipes'][2]);
                $exitCode = proc_close($worker['process']);
                $this->assertSame(0, $exitCode, trim((string) $stderr));
                $results[] = trim((string) $stdout);
            }
        } finally {
            @unlink($barrierPath);
            foreach ($workers as $worker) {
                @unlink($worker['ready_path']);
            }
        }

        $this->assertEqualsCanonicalizing(['created', 'conflict'], $results);
        $this->assertSame(1, Booking::query()->count());
        $this->assertSame(0, Inventory::query()->value('available_rooms'));
    }
}
