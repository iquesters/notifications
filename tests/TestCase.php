<?php

namespace Iquesters\Notifications\Tests;

use Diglactic\Breadcrumbs\Breadcrumbs;
use Diglactic\Breadcrumbs\ServiceProvider as BreadcrumbsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Iquesters\Foundation\FoundationServiceProvider;
use Iquesters\Foundation\Support\ConfProvider;
use Iquesters\Notifications\NotificationsServiceProvider;
use Iquesters\Notifications\Support\NotificationTypes;
use Iquesters\Notifications\Tests\Fixtures\User;
use Iquesters\Organisation\Models\Organisation;
use Iquesters\Organisation\OrganisationServiceProvider;
use Iquesters\Organisation\Support\OrganisationRoles;
use Iquesters\UserInterface\UserInterfaceServiceProvider;
use Laravel\Sanctum\SanctumServiceProvider;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionServiceProvider;
use Symfony\Component\Mime\Email;

#[WithMigration]
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Settings are cached before the test's transaction starts: start clean.
        Cache::flush();
        ConfProvider::forget();
        // "Right after the page has answered" happens at once in tests.
        app(\Illuminate\Contracts\Bus\Dispatcher::class)->withoutDispatchingAfterResponses();

        // A message type as a module declares it.
        app(NotificationTypes::class)->register('payment.rejected', [
            'label' => 'Payment rejected',
            'description' => 'The payer can send the details again.',
            'variables' => ['name' => 'Payer name', 'reference' => 'Order number', 'reason' => 'Why it was rejected', 'pay_url' => 'Pay page link'],
            'sample' => ['name' => 'Rahul Sen', 'reference' => 'ORD-0001', 'reason' => 'Not in the bank statement yet', 'pay_url' => 'https://example.test/pay/1'],
            'defaults' => ['email' => [
                'subject' => 'Payment for {{reference}} not accepted',
                'body' => "Dear {{name}},\n\nYour payment for **{{reference}}** wasn't accepted: {{reason}}.\n\n[Send the details again]({{pay_url}})",
            ]],
        ]);
    }

    protected function getPackageProviders($app): array
    {
        return [
            PermissionServiceProvider::class,
            SanctumServiceProvider::class,
            BreadcrumbsServiceProvider::class,
            FoundationServiceProvider::class,
            UserInterfaceServiceProvider::class,
            OrganisationServiceProvider::class,
            NotificationsServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return ['Breadcrumbs' => Breadcrumbs::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('permission.cache.store', 'array');
        // Sent mail is kept in memory to look at.
        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.from', ['address' => 'noreply@example.test', 'name' => 'The app']);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    /** @return Email[] the emails sent so far */
    protected function sentEmails(): array
    {
        return collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($m) => $m->getOriginalMessage())->all();
    }

    protected function makeUser(?string $role = null): User
    {
        $user = User::create([
            'name' => 'Test '.Str::random(6),
            'status' => 'active',
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => bcrypt(Str::random(20)),
        ]);

        if ($role) {
            Role::findOrCreate($role, 'web');
            $user->assignRole($role);
        }

        return $user;
    }

    protected function makeOrganisation(string $name = 'TTV Alumni'): Organisation
    {
        return Organisation::create([
            'uid' => (string) Str::ulid(),
            'name' => $name,
            'description' => "{$name} organisation",
            'status' => 'active',
        ]);
    }

    /** A user who belongs to the organisation ("admin": its org-admin). */
    protected function memberOf(Organisation $organisation, string $role = 'user'): User
    {
        $user = $this->makeUser($role);
        OrganisationRoles::join($user, $organisation, $role === 'admin' ? 'org-admin' : 'member');

        return $user;
    }
}
