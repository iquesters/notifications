<?php

namespace Iquesters\Notifications\Database\Seeders;

use Illuminate\Support\Facades\DB;
use Iquesters\Foundation\Database\Seeders\BaseSeeder;
use Iquesters\Foundation\Models\Module;
use Iquesters\Notifications\Support\NotificationForms;
use Iquesters\Notifications\Support\Templates;
use Spatie\Permission\Models\Role;

class NotificationsSeeder extends BaseSeeder
{
    /** Sees Templates, Email settings and Sent messages (limited to the organisations they administer). */
    public const MANAGE_PERMISSION = 'manage-notifications';

    /** Super-admins and organisation admins ("admin"). */
    public const MODULE_ROLES = ['super-admin', 'admin'];

    public const TEMPLATE_TABLE = 'notification-template-table';

    public const LOG_TABLE = 'notification-log-table';

    protected string $moduleName = 'notifications';

    protected string $description = 'notifications module: the messages modules send (email now), their wording per organisation, email settings, and what was sent';

    protected array $metas = [
        'module_icon' => 'fas fa-bell',
        'module_sidebar_menu' => [
            [
                'key' => 'notification-templates',
                'icon' => 'fas fa-envelope-open-text',
                'label' => 'Templates',
                'permission' => self::MANAGE_PERMISSION,
                'table_schema' => [
                    'slug' => self::TEMPLATE_TABLE,
                    'name' => 'Message templates',
                    'description' => 'The emails people get, and their wording',
                    'schema' => [
                        'entity' => 'notification_templates',
                        'data_url' => '/notifications/templates/data',
                        'record' => [
                            ['form' => NotificationForms::TEMPLATE, 'mode' => 'view', 'title' => 'Wording'],
                            ['html' => '/notifications/templates/{uid}/preview', 'title' => 'Preview'],
                            ['form' => NotificationForms::TEST, 'mode' => 'edit', 'title' => 'Test'],
                            ['form' => NotificationForms::RESET, 'mode' => 'edit', 'title' => 'Default wording', 'when' => ['customised' => '1']],
                        ],
                        'dt-options' => [
                            'columns' => [
                                ['data' => 'label', 'title' => 'Message', 'visible' => true],
                                ['data' => 'module', 'title' => 'From', 'visible' => true],
                                ['data' => 'channel', 'title' => 'Channel', 'visible' => true],
                                ['data' => 'subject', 'title' => 'Subject', 'visible' => true],
                                ['data' => 'wording', 'title' => 'Wording', 'visible' => true],
                                ['data' => 'status_label', 'title' => 'Sent', 'visible' => true],
                            ],
                            'options' => ['pageLength' => 25, 'order' => [], 'responsive' => true],
                        ],
                        'default_view_mode' => 'inbox',
                    ],
                ],
            ],
            [
                'key' => 'notification-settings',
                'icon' => 'fas fa-gear',
                'label' => 'Email settings',
                'route' => 'notifications.settings.index',
                'permission' => self::MANAGE_PERMISSION,
            ],
            [
                'key' => 'notification-logs',
                'icon' => 'fas fa-paper-plane',
                'label' => 'Sent messages',
                'permission' => self::MANAGE_PERMISSION,
                'table_schema' => [
                    'slug' => self::LOG_TABLE,
                    'name' => 'Sent messages',
                    'description' => 'What was sent to whom, and what happened',
                    'schema' => [
                        'entity' => 'notification_logs',
                        'data_url' => '/notifications/logs/data',
                        'record' => self::LOG_RECORD,
                        'dt-options' => [
                            'columns' => [
                                ['data' => 'name', 'title' => 'To', 'visible' => true],
                                ['data' => 'recipient', 'title' => 'Address', 'visible' => true],
                                ['data' => 'message', 'title' => 'Message', 'visible' => true],
                                ['data' => 'subject', 'title' => 'Subject', 'visible' => true],
                                ['data' => 'status_label', 'title' => 'Status', 'visible' => true],
                                ['data' => 'error', 'title' => 'Note', 'visible' => true],
                                ['data' => 'when', 'title' => 'When', 'visible' => true],
                            ],
                            'options' => ['pageLength' => 25, 'order' => [], 'responsive' => true],
                        ],
                        'default_view_mode' => 'table',
                    ],
                ],
            ],
        ],
    ];

    /** A sent message opened from the list: what happened, and "Send again" when it failed. */
    public const LOG_RECORD = [
        ['html' => '/notifications/logs/{uid}/summary', 'title' => 'Details'],
        ['form' => NotificationForms::RESEND, 'mode' => 'edit', 'title' => 'Send again', 'when' => ['can_resend' => '1']],
    ];

    protected array $permissions = [
        self::MANAGE_PERMISSION,
    ];

    protected function seedCustom(): void
    {
        $roles = collect(self::MODULE_ROLES)->mapWithKeys(fn (string $name) => [$name => Role::findOrCreate($name, 'web')]);
        $roles['admin']->givePermissionTo(self::MANAGE_PERMISSION);
        Module::where('name', $this->moduleName)->first()?->assignRoles($roles->pluck('id')->all());

        // The module's form schemas (rebuilt on every seed).
        NotificationForms::syncAll();
        // Sent messages set up before "Send again" existed: give the table its record sections.
        $this->upgradeLogTable();
        // The platform's wording of every message the installed modules declare.
        Templates::syncDefaults();
    }

    private function upgradeLogTable(): void
    {
        $row = DB::table('table_schemas')->where('slug', self::LOG_TABLE)->first();
        $schema = $row ? json_decode((string) $row->schema, true) : null;
        if (! is_array($schema) || ! empty($schema['record'])) {
            return;
        }
        $schema['record'] = self::LOG_RECORD;
        DB::table('table_schemas')->where('slug', self::LOG_TABLE)->update(['schema' => json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
    }
}
