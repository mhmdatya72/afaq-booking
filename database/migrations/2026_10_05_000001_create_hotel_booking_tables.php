<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotels', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('timezone', 64)->default('UTC');
            $table->timestamps();
        });

        Schema::create('room_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['hotel_id', 'name']);
        });

        Schema::create('inventory', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->date('inventory_date');
            $table->unsignedInteger('total_rooms');
            $table->unsignedInteger('available_rooms');
            $table->timestamps();
            $table->unique(['room_type_id', 'inventory_date']);
            $table->index(['inventory_date', 'room_type_id']);
        });

        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('booking_number', 21)->unique();
            $table->string('status', 20)->default('confirmed');
            $table->string('customer_name');
            $table->string('customer_contact', 255);
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('booking_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_id')->constrained('inventory')->restrictOnDelete();
            $table->unsignedInteger('rooms_count');
            $table->timestamps();
            $table->unique(['booking_id', 'inventory_id']);
            $table->index('inventory_id');
        });

        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('booking_items');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('inventory');
        Schema::dropIfExists('room_types');
        Schema::dropIfExists('hotels');
    }
};
