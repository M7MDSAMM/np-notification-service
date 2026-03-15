<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Notification extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'user_uuid',
        'template_key',
        'channels',
        'variables',
        'status',
        'idempotency_key',
        'scheduled_at',
        'last_error',
    ];

    protected $casts = [
        'channels'     => 'array',
        'variables'    => 'array',
        'scheduled_at' => 'datetime',
    ];

    protected $hidden = ['id'];

    protected static function booted(): void
    {
        static::creating(function (self $notification) {
            if (! $notification->uuid) {
                $notification->uuid = (string) Str::uuid();
            }
        });
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(NotificationAttempt::class, 'notification_uuid', 'uuid');
    }
}
