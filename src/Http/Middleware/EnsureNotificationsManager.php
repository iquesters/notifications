<?php

namespace Iquesters\Notifications\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Iquesters\Notifications\Support\NotificationAccess;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notification admin pages: super-admins and org-admins (of at least one
 * organisation). Which organisation each may change is checked again in the
 * controller. Auto-registered as `ensure.notifications.manager`.
 */
class EnsureNotificationsManager
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(NotificationAccess::isPlatformAdmin($request->user()) || NotificationAccess::organisationIds($request->user()) !== [], 403);

        return $next($request);
    }
}
