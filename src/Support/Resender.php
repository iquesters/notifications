<?php

namespace Iquesters\Notifications\Support;

use Iquesters\Notifications\Jobs\SendNotification;
use Iquesters\Notifications\Models\NotificationLog;

/**
 * "Send again" in Sent messages: a failed message, sent once more by an admin.
 * (A failed email was already tried a few times automatically, seconds apart.)
 */
class Resender
{
    /** Failed, and none of its values were secret (a sign-in code isn't kept, so it can't be resent; the person presses Resend on the page). */
    public static function canResend(NotificationLog $log): bool
    {
        $type = app(NotificationTypes::class)->get($log->type);

        return $log->status === NotificationLog::STATUS_FAILED && $type !== null && ($type['secret'] ?? []) === [];
    }

    /** Send it now (one try) and say whether it went out. */
    public static function resend(NotificationLog $log): bool
    {
        if (! self::canResend($log)) {
            return false;
        }
        $log->update(['status' => NotificationLog::STATUS_QUEUED, 'error' => null]);
        $job = new SendNotification($log->id);
        // One try only: the admin is waiting on the page.
        $job->tries = (int) $log->fresh()->attempts + 1;
        $job->handle();

        return $log->fresh()->status === NotificationLog::STATUS_SENT;
    }
}
