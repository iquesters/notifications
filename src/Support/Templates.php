<?php

namespace Iquesters\Notifications\Support;

use Illuminate\Support\Facades\Schema;
use Iquesters\Notifications\Models\NotificationTemplate;

/**
 * Which wording a message uses: the organisation's own when they changed it,
 * else the platform's (the module's default text, which a super-admin may
 * change too).
 */
class Templates
{
    /** The platform's templates follow the modules' defaults until a super-admin changes them. */
    public static function syncDefaults(): void
    {
        if (! Schema::hasTable('notification_templates')) {
            return;
        }
        foreach (app(NotificationTypes::class)->all() as $key => $type) {
            foreach ($type['defaults'] as $channel => $default) {
                $row = NotificationTemplate::query()->whereNull('organisation_id')->where('type', $key)->where('channel', $channel)->first();
                $values = ['subject' => $default['subject'] ?? null, 'body' => (string) ($default['body'] ?? '')];
                if (! $row) {
                    NotificationTemplate::create($values + ['organisation_id' => null, 'type' => $key, 'channel' => $channel, 'active' => true]);
                } elseif (! $row->customised) {
                    $row->update($values);
                }
            }
        }
    }

    /**
     * The wording to send: {subject, body, active, source} (source: organisation,
     * platform or module), or null when the type has none for the channel.
     */
    public static function effective(string $type, string $channel, ?int $organisationId): ?array
    {
        $rows = NotificationTemplate::query()
            ->where('type', $type)->where('channel', $channel)
            ->where(fn ($q) => $q->whereNull('organisation_id')->when($organisationId, fn ($q) => $q->orWhere('organisation_id', $organisationId)))
            ->get();
        // An app message (e.g. a sign-in code) uses only the platform's wording.
        $platformType = (bool) (app(NotificationTypes::class)->get($type)['platform'] ?? false);
        $own = $organisationId && ! $platformType ? $rows->firstWhere('organisation_id', $organisationId) : null;
        $platform = $rows->whereNull('organisation_id')->first();
        // Switched off by the organisation counts even when they kept the wording.
        if ($own && ! $own->active) {
            return ['subject' => $own->subject, 'body' => $own->body, 'active' => false, 'source' => 'organisation'];
        }
        $use = ($own && $own->customised) ? $own : $platform;
        if ($use) {
            return ['subject' => $use->subject, 'body' => $use->body, 'active' => (bool) $use->active, 'source' => $use === $own ? 'organisation' : 'platform'];
        }
        $default = app(NotificationTypes::class)->get($type)['defaults'][$channel] ?? null;

        return $default ? ['subject' => $default['subject'] ?? null, 'body' => (string) ($default['body'] ?? ''), 'active' => true, 'source' => 'module'] : null;
    }

    /**
     * An organisation's row for each type and channel (made from the platform's
     * wording, not "customised" until they change it), for the Templates list.
     */
    public static function ensureFor(?int $organisationId): void
    {
        self::syncDefaults();
        if (! $organisationId) {
            return;
        }
        $platform = NotificationTemplate::query()->whereNull('organisation_id')->get();
        $own = NotificationTemplate::query()->where('organisation_id', $organisationId)->get()->keyBy(fn ($t) => $t->type.'|'.$t->channel);
        foreach ($platform as $default) {
            $type = app(NotificationTypes::class)->get($default->type);
            // An app message (e.g. a sign-in code) has no organisation copy.
            if (! $own->has($default->type.'|'.$default->channel) && $type && ! $type['platform']) {
                NotificationTemplate::create([
                    'organisation_id' => $organisationId, 'type' => $default->type, 'channel' => $default->channel,
                    'subject' => $default->subject, 'body' => $default->body, 'active' => true, 'customised' => false,
                ]);
            }
        }
    }
}
