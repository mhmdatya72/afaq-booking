<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdempotencyKey extends Model
{
    protected $fillable = ['user_id', 'idempotency_key', 'request_hash', 'response_status', 'response_body'];

    protected function casts(): array
    {
        return ['response_body' => 'array', 'response_status' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
