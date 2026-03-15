<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class IdempotencyKey extends Model
{
    protected $fillable = [
        'uuid',
        'user_uuid',
        'idempotency_key',
        'request_hash',
        'notification_uuid',
    ];

    protected $hidden = ['id'];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (! $model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }
}
