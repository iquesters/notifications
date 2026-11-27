<?php

namespace Iquesters\Notifications;

use Iquesters\Foundation\System\Providers\BaseServiceProvider;
use Iquesters\Notifications\Support\NotificationTypes;

class NotificationsServiceProvider extends BaseServiceProvider
{
    // Routes, views, migrations, the seeder command, middleware and the conf
    // are auto-detected by BaseServiceProvider.

    public function register(): void
    {
        parent::register();

        // One registry: other modules declare their message types in their boot().
        $this->app->singleton(NotificationTypes::class);
    }
}
