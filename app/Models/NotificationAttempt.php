<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class NotificationAttempt extends Model
{
    protected $fillable = [
        'uuid',
        'notification_uuid',
        'channel',
        'status',
        'provider',
        'provider_message_id',
        'error_message',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'status' => 'string',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (! $model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }
}
