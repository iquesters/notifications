<?php

namespace Iquesters\Notifications\Support;

use InvalidArgumentException;

/**
 * The messages the installed modules can send, each declared by its module
 * (in its service provider, only when this module is installed):
 *
 *     app(NotificationTypes::class)->register('payment.rejected', [
 *         'label' => 'Payment rejected',
 *         'description' => 'The payer can send the details again.',
 *         'module' => 'payment',
 *         'variables' => ['name' => 'Payer name', 'reason' => 'Why it was rejected'],
 *         'sample' => ['name' => 'Rahul Sen', 'reason' => 'Not in the bank statement yet'],
 *         'defaults' => ['email' => ['subject' => '…', 'body' => '…']],
 *     ]);
 *
 * The defaults become the platform's templates (notifications:seed); an
 * organisation may override them.
 */
class NotificationTypes
{
    public const CHANNELS = ['email'];

    /** @var array<string, array> */
    private array $types = [];

    public function register(string $key, array $definition): void
    {
        if (! preg_match('/^[a-z][a-z0-9_-]*(\.[a-z0-9_-]+)+$/', $key)) {
            throw new InvalidArgumentException("Notification type \"{$key}\" must look like \"payment.rejected\".");
        }

        $this->types[$key] = [
            'key' => $key,
            'label' => (string) ($definition['label'] ?? $key),
            'description' => (string) ($definition['description'] ?? ''),
            'module' => (string) ($definition['module'] ?? strtok($key, '.')),
            'variables' => (array) ($definition['variables'] ?? []),
            'sample' => (array) ($definition['sample'] ?? []),
            'defaults' => array_intersect_key((array) ($definition['defaults'] ?? []), array_flip(self::CHANNELS)),
            // Can't be switched off (e.g. a sign-in code: nobody could sign in without it).
            'required' => (bool) ($definition['required'] ?? false),
            // The app's own message, not an organisation's (e.g. a sign-in code): only the
            // platform's wording (super-admins), never an organisation's copy.
            'platform' => (bool) ($definition['platform'] ?? false),
            // Values never kept in the log (e.g. the code itself); shown there as ••••.
            'secret' => array_values((array) ($definition['secret'] ?? [])),
        ];
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    public function get(string $key): ?array
    {
        return $this->types[$key] ?? null;
    }

    /** @return array<string, array> by key, sorted by module then label */
    public function all(): array
    {
        $types = $this->types;
        uasort($types, fn ($a, $b) => [$a['module'], $a['label']] <=> [$b['module'], $b['label']]);

        return $types;
    }
}
