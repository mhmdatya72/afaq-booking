# Afaq Hotel Booking API

## Development disclosure

This assessment project was developed with substantial assistance from AI coding tools. The submitter reviewed and ran the included application and tests and is responsible for understanding and explaining the implementation and its trade-offs.

A Laravel JSON API for reserving daily hotel room inventory. It requires authenticated users, reserves the half-open stay interval `[check_in_date, check_out_date)`, and persists an idempotent response for successful booking requests.

## Requirements and setup

- PHP 8.3+ (the project skeleton currently requires `^8.3`), Composer, and MySQL 8+ or PostgreSQL.
- Laravel 13, Sanctum, and PHPUnit are declared in Composer.
- SQLite is configured for the quick feature suite. Use MySQL or PostgreSQL to validate real row-lock behavior; SQLite does not implement `SELECT ... FOR UPDATE`.

```sh
composer install
cp .env.example .env
php artisan key:generate
```

Configure `.env` for MySQL, for example:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=afaq_booking
DB_USERNAME=afaq
DB_PASSWORD=secret
```

Then run migrations and sample data:

```sh
php artisan migrate --seed
php artisan serve
```

The seeder creates a sample hotel, a Standard room type, five rooms of daily inventory for the next 90 days, and `test@example.com` / `password`. Change or remove these development credentials in production. Create a personal access token for an authenticated user (from an authenticated user-management flow or with Tinker in a local environment):

```php
$user = App\Models\User::where('email', 'test@example.com')->first();
$token = $user->createToken('booking-client')->plainTextToken;
```

Send it as `Authorization: Bearer <token>`. API routes also emit JSON errors for API requests. The Postman collection is in `postman/Hotel-Booking-API.postman_collection.json`.

## API

All routes are under `/api/v1` and require Sanctum authentication.

### Create booking

`POST /api/v1/bookings`

Required headers: `Authorization: Bearer <token>`, `Accept: application/json`, and a client-generated `Idempotency-Key` (1–128 characters).

```json
{
  "hotel_id": 1,
  "room_type_id": 1,
  "check_in_date": "2026-10-10",
  "check_out_date": "2026-10-12",
  "rooms_count": 2,
  "customer_name": "Ada Lovelace",
  "customer_contact": "ada@example.test"
}
```

Success returns HTTP 201 and a booking resource, including its globally unique number in the form `AFP-YYYYMMDD-XXXXXX`. Availability conflicts return HTTP 409; invalid input returns HTTP 422. Replaying a successful same-key/same-payload request returns the original status and body with `Idempotent-Replayed: true`. Reusing a key with a different payload returns 409. The key is scoped to the authenticated user.

### Cancel booking

`POST /api/v1/bookings/{id}/cancel`

The owner receives the cancelled booking. Repeated cancellation returns that booking without restoring inventory twice. A booking belonging to another user is reported as 404.

Booking creation is rate-limited to 20 requests per minute per authenticated user (or source IP when unauthenticated requests reach the limiter).

## Schema

- `hotels` has room types.
- `room_types` belongs to one hotel.
- `inventory` has one row per room type and calendar date, with `total_rooms` and remaining `available_rooms`. `(room_type_id, inventory_date)` is unique. Conditional atomic updates prevent availability from falling below zero or cancellation from restoring above `total_rooms`.
- `bookings` belongs to the authenticated user and has a unique booking number and confirmed/cancelled status.
- `booking_items` contains one row for each occupied inventory date and booking, storing the number of rooms consumed that night. The checkout date has no item.
- `idempotency_keys` stores the user-scoped key, SHA-256 hash of the validated payload, and successful response. `(user_id, idempotency_key)` is unique.

Foreign keys protect all relationships. Availability, booking ownership/status, and date lookup paths have indexes. Inventory rows must be provisioned for every bookable night; a missing row fails closed as unavailable. Do not delete or rewrite inventory rows referenced by bookings.

## Concurrency and overbooking guarantees

Every create operation uses one database transaction. The service finds the requested daily inventory rows in date order and locks them with `SELECT ... FOR UPDATE` (`lockForUpdate`). It checks that every requested night exists and can satisfy the room count before changing any row. It then uses conditional atomic decrements (`available_rooms >= rooms_count`) and creates the booking and nightly items in the same transaction. A failed check, insert, or decrement rolls the entire transaction back. Locking rows in a stable order also reduces deadlocks for overlapping multi-night stays; Laravel retries deadlocked transactions up to three times.

For concurrent requests targeting the same date and room type, the second transaction waits for the row lock. After the first commits, the second reads the new availability and fails with 409 if the requested rooms no longer fit. The unique daily inventory key prevents ambiguous duplicate stock rows. These guarantees depend on a transactional database engine and must be exercised on MySQL/InnoDB or PostgreSQL in production. SQLite ignores row locks and is suitable here only for functional tests, not the concurrency guarantee.

Cancellation locks the booking row first. Only a confirmed booking restores its nightly item counts and changes state to cancelled, all within the same transaction. Concurrent or repeated cancels serialize on that booking lock, so only one request restores inventory.

Booking numbers use a cryptographically secure random six-character uppercase alphanumeric suffix. A database unique constraint is the final authority; the service retries number allocation up to five times if a collision occurs. The date prefix uses the application timezone.

## Idempotency details

The idempotency row is inserted in the same transaction as reservation and booking creation. A successful response is committed together with that booking, so a crash cannot commit one without the other. When a concurrent duplicate insert hits the unique key, the losing request reads the committed row and returns its response after comparing the request hash. A key used with different validated request data returns 409. Failed availability attempts roll back the idempotency row, allowing a later retry with that key after inventory changes.

## Tests

```sh
php artisan test
```

Feature coverage includes a successful multi-night booking, atomic rollback when one night is unavailable, idempotent replay and key misuse, cancellation and duplicate cancellation, competing requests against the remaining inventory, authentication, and owner scoping. The SQLite feature test is sequential because SQLite ignores row locks. `MySqlBookingConcurrencyTest` starts two independent PHP worker processes behind a barrier and verifies simultaneous database-backed booking requests; it requires MySQL and `proc_open`, and works on Windows or Linux. The k6 load test provides a higher-concurrency integration check and should run against MySQL/PostgreSQL.

Run the database-backed race test with MySQL configured as the test connection. Use a dedicated disposable test database: Laravel's database test traits reset its schema.

```sh
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=afaq_booking_test DB_USERNAME=root DB_PASSWORD= \
php artisan test --filter=MySqlBookingConcurrencyTest
```

In Windows PowerShell, set the same `DB_*` variables with `$env:DB_CONNECTION='mysql'`, `$env:DB_DATABASE='afaq_booking_test'`, and so on before running the Artisan command.

## k6 load test

Provision exactly five rooms for one room type on each night in the test stay. Start with 20 available on subsequent dates only if applicable; dates actually consumed by the requests must each begin with five available rooms. The k6 scenario sends 20 distinct requests using 20 VUs. Expected counters: exactly five HTTP 201, fifteen HTTP 409, and zero unexpected responses.

```sh
k6 run tests/load/bookings.js \
  -e BASE_URL=http://127.0.0.1:8000 \
  -e API_TOKEN=your-sanctum-token \
  -e HOTEL_ID=1 \
  -e ROOM_TYPE_ID=1 \
  -e CHECK_IN_DATE=2026-10-10 \
  -e CHECK_OUT_DATE=2026-10-11
```

Restore the inventory to five before each run. Run it against the same MySQL/PostgreSQL engine and configuration used in production; the script's thresholds fail the run if results differ from five successes and fifteen availability conflicts.

## Trade-offs

- Daily rows are explicit and simple to lock, at the cost of provisioning inventory ahead of booking dates.
- Booking items reference each night to make restoration exact and auditable, creating one item per booked night.
- Random booking numbers provide simple globally unique IDs with a DB-enforced collision retry. A centrally allocated sequence is appropriate if predictable collision-free allocation without retries becomes a requirement.
- This sample stores contact information in the booking row; production retention, access logging, and encryption-at-rest policies should follow the deployment's privacy requirements.
