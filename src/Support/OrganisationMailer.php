<?php

namespace Iquesters\Notifications\Support;

use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;
use Iquesters\Notifications\Models\NotificationChannel;

/**
 * Which email account sends an organisation's emails: the app's (MAIL_* in
 * .env, the default) or, when the organisation chose "our own email account",
 * its SMTP account (settings stored encrypted in notification_channels).
 */
class OrganisationMailer
{
    /**
     * [the mailer to send with, the From address].
     *
     * @return array{0: Mailer, 1: string}
     */
    public static function for(?int $organisationId): array
    {
        $settings = $organisationId ? NotificationChannel::for($organisationId, 'email') : null;
        if ($settings && self::usesOwnAccount($settings)) {
            return [self::build($settings), (string) $settings->setting('from_address')];
        }

        return [Mail::mailer(), (string) config('mail.from.address')];
    }

    public static function usesOwnAccount(NotificationChannel $settings): bool
    {
        return $settings->setting('send_via') === 'own'
            && trim((string) $settings->setting('smtp_host')) !== ''
            && trim((string) $settings->setting('from_address')) !== '';
    }

    /** A mailer for the organisation's SMTP account (made for this send; not cached). */
    public static function build(NotificationChannel $settings): Mailer
    {
        $encryption = (string) $settings->setting('smtp_encryption', 'tls');

        return Mail::build([
            'transport' => 'smtp',
            'host' => (string) $settings->setting('smtp_host'),
            'port' => (int) $settings->setting('smtp_port', 587),
            // SSL (465): an encrypted connection from the start; TLS (587): STARTTLS; none: plain.
            'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'encryption' => $encryption === 'none' ? null : $encryption,
            'username' => (string) $settings->setting('smtp_username'),
            'password' => (string) $settings->setting('smtp_password'),
            'timeout' => 20,
        ]);
    }
}
