<?php

namespace Iquesters\Notifications\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Iquesters\Foundation\Support\ConfProvider;
use Iquesters\Notifications\Enums\Module;
use Iquesters\Notifications\Models\NotificationChannel;
use Iquesters\Notifications\Models\NotificationLog;
use Iquesters\Notifications\Support\Renderer;
use Iquesters\Notifications\Support\Templates;
use Iquesters\Organisation\Models\Organisation;

/** Sends one logged message (email for now) and records the outcome: through a queue, or right after the page has answered. */
class SendNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Seconds before the 2nd, 3rd… try. */
    public array $backoff = [60, 300, 900];

    public function __construct(public int $logId)
    {
        $this->tries = max(1, (int) ConfProvider::from(Module::NOTIFICATIONS)->tries);
    }

    public function handle(): void
    {
        $log = NotificationLog::query()->find($this->logId);
        if (! $log || $log->status === NotificationLog::STATUS_SENT) {
            return;
        }
        $log->increment('attempts');
        $template = Templates::effective($log->type, $log->channel, $log->organisation_id);
        $required = (bool) (app(\Iquesters\Notifications\Support\NotificationTypes::class)->get($log->type)['required'] ?? false);
        if (! $template || (! $template['active'] && ! $required)) {
            $log->update(['status' => NotificationLog::STATUS_SKIPPED, 'error' => 'No active template when sending.']);

            return;
        }
        try {
            $this->deliver($log, $template);
        } catch (\Throwable $e) {
            // Through a real queue: its worker tries again later (failed() records the last error).
            // After the page answers the job runs on the "sync" connection, which never retries: handled here.
            if ($this->job && $this->job->getConnectionName() !== 'sync') {
                throw $e;
            }
            // Without a queue (no cron or worker needed): tried again here, a few
            // seconds apart, up to `tries` in all. This runs after the page has
            // answered, so nobody waits. Still failing: recorded as failed, and an
            // admin can press "Send again" in Sent messages.
            while ($log->attempts < $this->tries) {
                sleep(self::retryPauseSeconds($log->attempts));
                $log->increment('attempts');
                try {
                    $this->deliver($log, $template);

                    return;
                } catch (\Throwable $next) {
                    $e = $next;
                }
            }
            $log->update(['status' => NotificationLog::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 1000)]);
        }
    }

    /** Seconds to wait before the next try without a queue: 5, then 15 (0 in tests). */
    public static function retryPauseSeconds(int $attemptsSoFar): int
    {
        return app()->runningUnitTests() ? 0 : ($attemptsSoFar <= 1 ? 5 : 15);
    }

    private function deliver(NotificationLog $log, array $template): void
    {
        if ($log->channel === 'email') {
            $this->sendEmail($log, $template);
        }
    }

    public function failed(\Throwable $e): void
    {
        NotificationLog::query()->whereKey($this->logId)->update(['status' => NotificationLog::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 1000)]);
    }

    private function sendEmail(NotificationLog $log, array $template): void
    {
        self::deliverEmail($log, $template, (array) $log->variables);
    }

    /**
     * Send the email filled with $values and mark the log sent. The log keeps the
     * subject as filled with its own (stored) values, so a secret such as a
     * sign-in code, left out of the log, never lands in it.
     */
    public static function deliverEmail(NotificationLog $log, array $template, array $values): void
    {
        $subject = self::subject($template, $values);
        [, $fromName, $replyTo] = self::sender($log->organisation_id);
        $html = self::html($log->organisation_id, $template, $values);
        // The organisation's own email account when it set one up, else the app's.
        [$mailer, $fromAddress] = \Iquesters\Notifications\Support\OrganisationMailer::for($log->organisation_id);

        $mailer->html($html, function ($message) use ($log, $subject, $fromName, $replyTo, $fromAddress) {
            $message->to($log->recipient, $log->recipient_name)->subject($subject);
            $message->from($fromAddress, $fromName);
            if ($replyTo) {
                $message->replyTo($replyTo, $fromName);
            }
        });

        $log->update(['status' => NotificationLog::STATUS_SENT, 'subject' => self::subject($template, (array) $log->variables), 'sent_at' => now(), 'error' => null]);
    }

    public static function subject(array $template, array $values): string
    {
        return Renderer::text($template['subject'] ?? null, $values) ?: self::appName();
    }

    /**
     * The whole email: a header with who it is from (an organisation's message:
     * its logo and name; an app message such as a sign-in code: the app's logo
     * and name), the message, a footer.
     */
    public static function html(?int $organisationId, array $template, array $values): string
    {
        [$organisation] = self::sender($organisationId);
        $appName = self::appName();

        return view('notifications::emails.layout', [
            'body' => Renderer::html($template['body'] ?? '', $values),
            'organisation' => $organisation,
            'brandName' => $organisation?->name ?: $appName,
            // The organisation's logo, else the app's.
            'logoUrl' => self::logoUrl($organisation) ?? self::appLogoUrl(),
            'appName' => $appName,
            'subject' => self::subject($template, $values),
            'credit' => self::footerCredit(),
        ])->render();
    }

    /** [the organisation, the name mail comes from (its setting, else its name, else the app's), its reply-to address]. */
    public static function sender(?int $organisationId): array
    {
        $organisation = $organisationId ? Organisation::query()->find($organisationId) : null;
        $settings = NotificationChannel::for($organisationId, 'email');
        $fromName = trim((string) $settings?->setting('from_name')) ?: ($organisation?->name ?: self::appName());
        $replyTo = trim((string) $settings?->setting('reply_to')) ?: null;

        return [$organisation, $fromName, $replyTo];
    }

    /** The organisation's logo (absolute: an email needs the full address), if it has one. */
    public static function logoUrl(?Organisation $organisation): ?string
    {
        $profile = 'Iquesters\Organisation\Support\OrganisationProfile';

        $url = $organisation && class_exists($profile) && $organisation->getMetaValue($profile::LOGO) && Route::has('organisations.logo')
            ? route('organisations.logo', $organisation->uid)
            : null;

        // An address only this computer knows (localhost) shows as a broken image in the inbox: left out.
        return $url && preg_match('#^https?://(localhost|127\.0\.0\.1|\[::1\])([:/]|$)#i', $url) ? null : $url;
    }

    /** The footer's last line (who built the platform) and its link, from the settings; null hides it. */
    public static function footerCredit(): ?array
    {
        try {
            $conf = ConfProvider::from(Module::NOTIFICATIONS);
            $text = trim((string) $conf->footer_credit_text);
            $url = trim((string) $conf->footer_credit_url);
        } catch (\Throwable $e) {
            return null;
        }

        return $text === '' ? null : ['text' => $text, 'url' => $url !== '' ? $url : null];
    }

    /** The app's name as set up (user-interface), else APP_NAME. */
    public static function appName(): string
    {
        $assets = 'Iquesters\UserInterface\Support\Assets';
        try {
            $name = class_exists($assets) ? trim((string) $assets::appName()) : '';
        } catch (\Throwable $e) {
            $name = '';
        }

        return $name !== '' ? $name : (string) config('app.name');
    }

    /** The app's logo as set up (user-interface), as a full address. */
    public static function appLogoUrl(): ?string
    {
        $assets = 'Iquesters\UserInterface\Support\Assets';
        try {
            $url = class_exists($assets) ? trim((string) $assets::logoUrl()) : '';
        } catch (\Throwable $e) {
            $url = '';
        }
        if ($url === '') {
            return null;
        }

        $url = preg_match('#^(https?:)?//#i', $url) || str_starts_with($url, 'data:') ? $url : url($url);

        // An address only this computer knows (localhost) shows as a broken image in the inbox: left out.
        return preg_match('#^https?://(localhost|127\.0\.0\.1|\[::1\])([:/]|$)#i', $url) ? null : $url;
    }
}
