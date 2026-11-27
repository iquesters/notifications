<?php

namespace Iquesters\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** A message's wording for one channel: the platform's default (organisation_id null) or an organisation's own. */
class NotificationTemplate extends Model
{
    protected $table = 'notification_templates';

    protected $fillable = ['uid', 'organisation_id', 'type', 'channel', 'subject', 'body', 'active', 'customised', 'created_by', 'updated_by'];

    protected $casts = ['active' => 'boolean', 'customised' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(fn (self $template) => $template->uid ??= (string) Str::ulid());
    }
}
