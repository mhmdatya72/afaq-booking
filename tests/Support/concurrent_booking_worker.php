<?php

use App\Models\User;
use App\Services\BookingService;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $userId, $encodedPayload, $idempotencyKey, $barrierPath, $readyPath] = $argv;
file_put_contents($readyPath, 'ready');

$barrierDeadline = microtime(true) + 20;
while (! file_exists($barrierPath)) {
    if (microtime(true) >= $barrierDeadline) {
        fwrite(STDERR, "Timed out waiting for the concurrency barrier.\n");
        exit(1);
    }

    usleep(10_000);
}

try {
    $user = User::query()->findOrFail((int) $userId);
    $payload = json_decode(base64_decode($encodedPayload, true), true, flags: JSON_THROW_ON_ERROR);
    app(BookingService::class)->create($user, $payload, $idempotencyKey);
    echo 'created';
} catch (ConflictHttpException) {
    echo 'conflict';
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
