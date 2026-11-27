<?php

namespace Iquesters\Notifications\Config;

use Iquesters\Foundation\Support\BaseConf;
use Iquesters\Notifications\Enums\Module;

/** Notification settings (app-wide; each organisation's are in notification_channels). */
class NotificationsConf extends BaseConf
{
    protected ?string $identifier = Module::NOTIFICATIONS;

    /** Off: nothing is sent (each message is logged as skipped), e.g. on a test copy of the app. */
    protected bool $send_enabled;

    /**
     * Send through a queue (needs a queue worker; failed sends are retried).
     * Off (default): each message is sent right after the page has answered, with no worker.
     */
    protected bool $use_queue;

    /** The queue the messages go through (with use_queue). */
    protected string $queue;

    /** How many times a failed message is tried (with use_queue). */
    protected int $tries;

    /** The last line of every email: who built the platform (empty: no line). */
    protected string $footer_credit_text;

    /** Where that line links to (empty: plain text). */
    protected string $footer_credit_url;

    protected function prepareDefault(BaseConf $default_values)
    {
        $default_values->send_enabled = true;
        $default_values->use_queue = false;
        $default_values->queue = 'default';
        $default_values->tries = 3;
        $default_values->footer_credit_text = 'Platform developed by Iquesters Solutions LLP';
        $default_values->footer_credit_url = 'https://iquesters.com';
    }

    protected function rules(): array
    {
        return [
            'send_enabled' => 'boolean',
            'use_queue' => 'boolean',
            'queue' => 'string|max:64',
            'tries' => 'integer|between:1,10',
            'footer_credit_text' => 'nullable|string|max:200',
            'footer_credit_url' => 'nullable|url|max:255',
        ];
    }
}
