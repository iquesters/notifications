<?php

namespace Iquesters\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** An organisation's (or the platform's) settings for one channel, e.g. email: from_name, reply_to. Settings are stored encrypted. */
class NotificationChannel extends Model
{
    protected $table = 'notification_channels';

    protected $fillable = ['uid', 'organisation_id', 'channel', 'settings', 'active', 'tested_at', 'test_result', 'created_by', 'updated_by'];

    protected $casts = ['settings' => 'encrypted:array', 'active' => 'boolean', 'tested_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $channel) => $channel->uid ??= (string) Str::ulid());
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return ((array) $this->settings)[$key] ?? $default;
    }

    /** The organisation's settings for a channel, if any. */
    public static function for(?int $organisationId, string $channel): ?self
    {
        return self::query()->where('organisation_id', $organisationId)->where('channel', $channel)->first();
    }
}
