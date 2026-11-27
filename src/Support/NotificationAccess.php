<?php

namespace Iquesters\Notifications\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Iquesters\Organisation\Models\Organisation;
use Iquesters\Organisation\Support\OrganisationAccess;
use Iquesters\Organisation\Support\OrganisationContext;

/**
 * Who manages notifications: super-admins (every organisation, and the
 * platform's default wording) and each organisation's org-admins (theirs).
 */
class NotificationAccess
{
    /** @return int[] the organisations the user manages notifications of */
    public static function organisationIds(?Authenticatable $user): array
    {
        if (! $user) {
            return [];
        }
        if (OrganisationAccess::isPlatformAdmin($user)) {
            return Organisation::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return array_map('intval', OrganisationAccess::adminOrganisationIds($user));
    }

    public static function isPlatformAdmin(?Authenticatable $user): bool
    {
        return OrganisationAccess::isPlatformAdmin($user);
    }

    /** May the user change this organisation's (or, with null, the platform's) notifications? */
    public static function canManage(?Authenticatable $user, ?int $organisationId): bool
    {
        return $organisationId === null
            ? self::isPlatformAdmin($user)
            : in_array($organisationId, self::organisationIds($user), true);
    }

    /**
     * The organisation the screens work on: the one chosen in the header, else
     * the first one managed. A super-admin with no organisation chosen works on
     * the platform's defaults (null).
     */
    public static function workingOrganisationId(?Authenticatable $user): ?int
    {
        $ids = self::organisationIds($user);
        $context = app()->bound(OrganisationContext::class) ? app(OrganisationContext::class)->id($user) : null;
        if ($context && in_array((int) $context, $ids, true)) {
            return (int) $context;
        }
        if (self::isPlatformAdmin($user)) {
            return null;
        }

        return $ids === [] ? null : (int) Organisation::query()->whereIn('id', $ids)->orderBy('name')->value('id');
    }
}
