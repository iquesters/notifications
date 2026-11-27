<?php

namespace Iquesters\Notifications\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** The module's form schemas (rebuilt by notifications:seed). */
class NotificationForms
{
    /** A template's wording ({uid} = the template). */
    public const TEMPLATE = 'notification-template';

    /** "Send me a test" ({uid} = the template). */
    public const TEST = 'notification-template-test';

    /** "Use the default wording again" ({uid} = the template). */
    public const RESET = 'notification-template-reset';

    /** An organisation's email settings: from name, reply-to ({uid} = the organisation). */
    public const EMAIL_SETTINGS = 'notification-email-settings';

    /** "Send me a test email" through the organisation's settings ({uid} = the organisation). */
    public const EMAIL_TEST = 'notification-email-test';

    /** "Send again" for a failed message ({uid} = the sent-message log). */
    public const RESEND = 'notification-log-resend';

    public static function syncAll(): void
    {
        self::upsert(self::TEMPLATE, 'Notification template', self::template());
        self::upsert(self::TEST, 'Send a test notification', self::button('/notifications/templates/{uid}/test', 'Send me a test', 'Sending'));
        self::upsert(self::RESET, 'Reset a notification template', self::button('/notifications/templates/{uid}/reset', 'Use the default wording again', 'Resetting'));
        self::upsert(self::EMAIL_SETTINGS, 'Email settings', self::emailSettings());
        self::upsert(self::RESEND, 'Send a failed message again', self::button('/notifications/logs/{uid}/resend', 'Send again', 'Sending'));
        self::upsert(self::EMAIL_TEST, 'Send a test email', self::button('/notifications/settings/{uid}/test', 'Send me a test email', 'Sending'));
    }

    public static function template(): array
    {
        return [
            'endpoint' => '/notifications/templates/{uid}',
            'method' => 'PUT',
            'submitButtonLabel' => 'Save wording',
            'allowEdit' => true,
            'modes' => ['edit' => ['allowCancel' => true, 'allowDelete' => false], 'view' => ['allowDelete' => false]],
            'fields' => [
                ['id' => 'active', 'label' => 'Send this message', 'type' => 'checkbox', 'helperText' => 'Off: this message isn\'t sent (it shows as skipped in Sent messages).'],
                ['id' => 'subject', 'label' => 'Subject', 'type' => 'text', 'required' => true, 'maxLength' => 255, 'helperText' => 'Placeholders such as {{name}} are filled in for each person.'],
                ['id' => 'body', 'label' => 'Message', 'type' => 'textarea', 'required' => true, 'rows' => 12, 'helperText' => 'Plain text with placeholders ({{name}}). **bold**, lists (- item) and links ([Pay now]({{pay_url}})) work. The placeholders this message has are listed in the preview.'],
            ],
        ];
    }

    public static function button(string $endpoint, string $label, string $busy): array
    {
        return [
            'endpoint' => $endpoint,
            'method' => 'POST',
            'submitButtonLabel' => $label,
            'submittingLabel' => $busy,
            'allowCancel' => false,
            'modes' => ['edit' => ['allowCancel' => false, 'allowDelete' => false]],
            'fields' => [],
        ];
    }

    public static function emailSettings(): array
    {
        return [
            'endpoint' => '/notifications/settings/{uid}',
            'method' => 'PUT',
            'submitButtonLabel' => 'Save email settings',
            'allowEdit' => true,
            'modes' => ['edit' => ['allowCancel' => true, 'allowDelete' => false], 'view' => ['allowDelete' => false]],
            'fields' => [
                ['id' => 'sender', 'type' => 'section', 'label' => 'Who the emails come from', 'description' => 'The name people see, and where their replies go.'],
                ['id' => 'from_name', 'label' => 'Sender name', 'type' => 'text', 'maxLength' => 100, 'placeholder' => 'The organisation\'s name', 'helperText' => 'Shown as the sender, e.g. "TTV Alumni Association". Empty: the organisation\'s name.'],
                ['id' => 'reply_to', 'label' => 'Replies go to', 'type' => 'email', 'maxLength' => 255, 'placeholder' => 'e.g. office@example.org', 'helperText' => 'The address people reach when they press Reply. Empty: the sending address.'],

                ['id' => 'account', 'type' => 'section', 'label' => 'Which email account sends them', 'description' => 'The app\'s own account works out of the box. With your own, emails come from your address (your domain needs SPF / DKIM for your mail server, or they may land in Spam).'],
                ['id' => 'send_via', 'label' => 'Send emails through', 'type' => 'radio', 'required' => true, 'default' => 'app', 'inline' => false, 'options' => [
                    ['value' => 'app', 'label' => 'The app\'s email account'],
                    ['value' => 'own', 'label' => 'Our own email account'],
                ]],
                ['id' => 'from_address', 'label' => 'From address', 'type' => 'email', 'required' => true, 'maxLength' => 255, 'placeholder' => 'e.g. office@example.org', 'helperText' => 'The address the emails come from (usually the account\'s own address).', 'showWhen' => ['field' => 'send_via', 'equals' => 'own']],
                ['id' => 'smtp_host', 'label' => 'Outgoing server (SMTP)', 'type' => 'text', 'required' => true, 'maxLength' => 255, 'placeholder' => 'e.g. smtp.gmail.com', 'showWhen' => ['field' => 'send_via', 'equals' => 'own']],
                ['id' => 'smtp_port', 'label' => 'Port', 'type' => 'number', 'required' => true, 'min' => 1, 'max' => 65535, 'default' => 587, 'placeholder' => 'e.g. 587', 'showWhen' => ['field' => 'send_via', 'equals' => 'own']],
                ['id' => 'smtp_encryption', 'label' => 'Security', 'type' => 'select', 'required' => true, 'default' => 'tls', 'options' => [
                    ['value' => 'tls', 'label' => 'TLS / STARTTLS (port 587)'],
                    ['value' => 'ssl', 'label' => 'SSL (port 465)'],
                    ['value' => 'none', 'label' => 'None'],
                ], 'showWhen' => ['field' => 'send_via', 'equals' => 'own']],
                ['id' => 'smtp_username', 'label' => 'Username', 'type' => 'text', 'required' => true, 'maxLength' => 255, 'placeholder' => 'e.g. office@example.org', 'showWhen' => ['field' => 'send_via', 'equals' => 'own']],
                ['id' => 'smtp_password', 'label' => 'Password', 'type' => 'password', 'maxLength' => 255, 'omitIfEmpty' => true, 'helperText' => 'Stored encrypted and never shown again. Leave empty to keep the saved one. Gmail and Google Workspace: an app password.', 'showWhen' => ['field' => 'send_via', 'equals' => 'own']],
            ],
        ];
    }

    private static function upsert(string $slug, string $name, array $schema): void
    {
        if (! Schema::hasTable('form_schemas')) {
            return;
        }

        $existing = DB::table('form_schemas')->where('slug', $slug)->first(['uid', 'created_at']);
        DB::table('form_schemas')->updateOrInsert(['slug' => $slug], [
            'uid' => $existing->uid ?? (string) Str::ulid(),
            'name' => $name,
            'description' => "Notifications module form ({$slug}); notifications:seed rebuilds it",
            'schema' => json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'active',
            'created_at' => $existing->created_at ?? now(),
            'updated_at' => now(),
        ]);
    }
}
