<?php

namespace Iquesters\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** One message to one person on one channel: queued, then sent, failed or skipped. */
class NotificationLog extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const LABELS = [
        self::STATUS_QUEUED => 'Sending',
        self::STATUS_SENT => 'Sent',
        self::STATUS_FAILED => 'Failed',
        self::STATUS_SKIPPED => 'Skipped',
    ];

    protected $table = 'notification_logs';

    protected $fillable = ['uid', 'organisation_id', 'type', 'channel', 'recipient', 'recipient_name', 'user_id', 'ref_type', 'ref_id', 'variables', 'subject', 'status', 'error', 'attempts', 'sent_at'];

    protected $casts = ['variables' => 'array', 'sent_at' => 'datetime', 'attempts' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn (self $log) => $log->uid ??= (string) Str::ulid());
    }

    public function statusLabel(): string
    {
        return self::LABELS[$this->status] ?? $this->status;
    }
}
