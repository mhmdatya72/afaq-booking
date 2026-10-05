<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Inventory extends Model
{
    protected $table = 'inventory';

    protected $fillable = ['room_type_id', 'inventory_date', 'total_rooms', 'available_rooms'];

    protected function casts(): array
    {
        return ['inventory_date' => 'date', 'total_rooms' => 'integer', 'available_rooms' => 'integer'];
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function bookingItems(): HasMany
    {
        return $this->hasMany(BookingItem::class);
    }
}
