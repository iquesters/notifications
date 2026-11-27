<?php

namespace Iquesters\Notifications;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Iquesters\Foundation\Support\ConfProvider;
use Iquesters\Notifications\Enums\Module;
use Iquesters\Notifications\Jobs\SendNotification;
use Iquesters\Notifications\Models\NotificationLog;
use Iquesters\Notifications\Support\NotificationTypes;
use Iquesters\Notifications\Support\Templates;

/**
 * Send a message of a declared type to one person:
 *
 *     Notifications::send('payment.rejected', $organisation, ['email' => …, 'name' => …, 'user_id' => …],
 *         ['name' => …, 'reason' => …], ref: $payment);
 *
 * Each channel the type has a template for is logged and sent (right after
 * the page has answered, or through a queue with the use_queue setting); a missing
 * address, a switched-off template or sending turned off is logged as
 * skipped. Never throws: a problem here must not break the caller's flow.
 */
class Notifications
{
    public static function send(string $type, Model|int|null $organisation, array $recipient, array $variables = [], ?Model $ref = null): void
    {
        try {
            $types = app(NotificationTypes::class);
            if (! $types->has($type)) {
                Log::warning("Notification type {$type} is not registered: nothing sent.");

                return;
            }
            $organisationId = $organisation instanceof Model ? (int) $organisation->getKey() : $organisation;
            $conf = ConfProvider::from(Module::NOTIFICATIONS);

            foreach (array_keys($types->get($type)['defaults']) as $channel) {
                $address = trim((string) ($recipient[$channel === 'email' ? 'email' : 'phone'] ?? ''));
                $template = Templates::effective($type, $channel, $organisationId);
                $required = (bool) $types->get($type)['required'];
                $skip = match (true) {
                    $address === '' => 'No '.($channel === 'email' ? 'email address' : 'number').'.',
                    ! $template => 'No template.',
                    ! $template['active'] && ! $required => 'This message is switched off.',
                    ! $conf->send_enabled && ! $required => 'Sending is turned off.',
                    default => null,
                };
                $log = NotificationLog::create([
                    'organisation_id' => $organisationId,
                    'type' => $type,
                    'channel' => $channel,
                    'recipient' => $address !== '' ? mb_strtolower($address) : '—',
                    'recipient_name' => $recipient['name'] ?? null,
                    'user_id' => $recipient['user_id'] ?? null,
                    'ref_type' => $ref ? $ref->getMorphClass() : null,
                    'ref_id' => $ref ? (string) ($ref->uid ?? $ref->getKey()) : null,
                    'variables' => self::forLog($type, $variables),
                    'status' => $skip ? NotificationLog::STATUS_SKIPPED : NotificationLog::STATUS_QUEUED,
                    'error' => $skip,
                ]);
                if (! $skip) {
                    self::dispatch($log->id, (bool) $conf->use_queue, (string) $conf->queue);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Notification '.$type.' not sent: '.$e->getMessage());
        }
    }

    /**
     * Send one email now and wait for it (e.g. a sign-in code: it must arrive
     * within seconds, never later or through a queue). Logged like the others,
     * with the type's secret values (the code) left out of the log.
     *
     * @throws \RuntimeException when it can't be sent (the caller tells the person)
     */
    public static function sendNow(string $type, Model|int|null $organisation, array $recipient, array $variables = []): void
    {
        $types = app(NotificationTypes::class);
        if (! $types->has($type)) {
            throw new \RuntimeException("Notification type {$type} is not registered.");
        }
        $address = trim((string) ($recipient['email'] ?? ''));
        if ($address === '') {
            throw new \RuntimeException('No email address.');
        }
        $organisationId = $organisation instanceof Model ? (int) $organisation->getKey() : $organisation;
        $template = Templates::effective($type, 'email', $organisationId);
        if (! $template) {
            throw new \RuntimeException("No email template for {$type}.");
        }

        $log = NotificationLog::create([
            'organisation_id' => $organisationId,
            'type' => $type,
            'channel' => 'email',
            'recipient' => mb_strtolower($address),
            'recipient_name' => $recipient['name'] ?? null,
            'user_id' => $recipient['user_id'] ?? null,
            'variables' => self::forLog($type, $variables),
            'status' => NotificationLog::STATUS_QUEUED,
            'attempts' => 1,
        ]);
        try {
            SendNotification::deliverEmail($log, $template, $variables);
        } catch (\Throwable $e) {
            $log->update(['status' => NotificationLog::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 1000)]);

            throw new \RuntimeException('The email could not be sent: '.$e->getMessage(), 0, $e);
        }
    }

    /** The values as kept in the log: a type's secret ones (e.g. a sign-in code) as ••••. */
    private static function forLog(string $type, array $variables): array
    {
        foreach ((array) (app(NotificationTypes::class)->get($type)['secret'] ?? []) as $name) {
            if (array_key_exists($name, $variables)) {
                $variables[$name] = '••••';
            }
        }

        return $variables;
    }

    /**
     * Always after the caller's transaction commits (e.g. the payment really is
     * verified). With a queue: a job for the worker. Without (default): sent
     * right after the page has answered, so nobody waits for the mail server.
     */
    private static function dispatch(int $logId, bool $useQueue, string $queue): void
    {
        if ($useQueue) {
            SendNotification::dispatch($logId)->onQueue($queue)->afterCommit();

            return;
        }
        DB::afterCommit(fn () => app(Dispatcher::class)->dispatchAfterResponse(new SendNotification($logId)));
    }
}
