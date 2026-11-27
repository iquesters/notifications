<?php

namespace Iquesters\Notifications\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Iquesters\Notifications\Jobs\SendNotification;
use Iquesters\Notifications\Models\NotificationChannel;
use Iquesters\Notifications\Support\OrganisationMailer;
use Iquesters\Notifications\Support\NotificationAccess;
use Iquesters\Notifications\Support\NotificationForms;
use Iquesters\Organisation\Models\Organisation;

/** An organisation's email settings (form notification-email-settings): who the emails come from, where replies go. */
class SettingsController extends Controller
{
    /** The organisation worked in, else the first one managed. */
    public function index(Request $request): RedirectResponse
    {
        $id = NotificationAccess::workingOrganisationId($request->user())
            ?? (NotificationAccess::organisationIds($request->user())[0] ?? null);
        $organisation = $id ? Organisation::query()->find($id) : null;
        abort_unless($organisation, 404, 'Create an organisation first.');

        return redirect()->route('notifications.settings.edit', $organisation->uid);
    }

    public function edit(Request $request, string $uid): View
    {
        $organisation = $this->organisation($request, $uid);
        $email = NotificationChannel::for((int) $organisation->id, 'email');

        return view('notifications::admin.settings', [
            'organisation' => $organisation,
            'organisations' => Organisation::query()->whereIn('id', NotificationAccess::organisationIds($request->user()))->orderBy('name')->get(['uid', 'name']),
            'formId' => NotificationForms::EMAIL_SETTINGS,
            'testFormId' => NotificationForms::EMAIL_TEST,
            'lastTest' => $email?->tested_at ? ['at' => $email->tested_at, 'result' => $email->test_result] : null,
            'formData' => [
                'uid' => $organisation->uid,
                'from_name' => $email?->setting('from_name'),
                'reply_to' => $email?->setting('reply_to'),
                'send_via' => $email?->setting('send_via') ?: 'app',
                'from_address' => $email?->setting('from_address'),
                'smtp_host' => $email?->setting('smtp_host'),
                'smtp_port' => $email?->setting('smtp_port') ?: 587,
                'smtp_encryption' => $email?->setting('smtp_encryption') ?: 'tls',
                'smtp_username' => $email?->setting('smtp_username'),
                // Never sent back to the browser; empty means "keep the saved one".
                'smtp_password' => '',
            ],
        ]);
    }

    public function update(Request $request, string $uid): JsonResponse
    {
        $organisation = $this->organisation($request, $uid);
        $channel = NotificationChannel::for((int) $organisation->id, 'email') ?? new NotificationChannel(['organisation_id' => $organisation->id, 'channel' => 'email', 'created_by' => $request->user()->id]);
        // Not sent (e.g. only the sender name changed): the current choice stays (the app's account by default).
        $sendVia = $request->input('send_via') ?: ($channel->setting('send_via') ?: 'app');
        $own = $sendVia === 'own';
        $hasPassword = trim((string) $channel->setting('smtp_password')) !== '';
        $data = $request->validate([
            'from_name' => ['nullable', 'string', 'max:100'],
            'reply_to' => ['nullable', 'email', 'max:255'],
            'send_via' => ['nullable', 'in:app,own'],
            'from_address' => [$own ? 'required' : 'nullable', 'email', 'max:255'],
            'smtp_host' => [$own ? 'required' : 'nullable', 'string', 'max:255'],
            'smtp_port' => [$own ? 'required' : 'nullable', 'integer', 'between:1,65535'],
            'smtp_encryption' => [$own ? 'required' : 'nullable', 'in:tls,ssl,none'],
            'smtp_username' => [$own ? 'required' : 'nullable', 'string', 'max:255'],
            // Needed the first time; afterwards empty keeps the saved one.
            'smtp_password' => [$own && ! $hasPassword ? 'required' : 'nullable', 'string', 'max:255'],
        ], ['smtp_password.required' => 'Enter the account\'s password (for Gmail / Google Workspace: an app password).']);

        $settings = [...(array) $channel->settings,
            'from_name' => trim((string) ($data['from_name'] ?? '')),
            'reply_to' => trim((string) ($data['reply_to'] ?? '')),
            'send_via' => $sendVia,
        ];
        if ($own) {
            $settings = [...$settings,
                'from_address' => mb_strtolower(trim((string) $data['from_address'])),
                'smtp_host' => trim((string) $data['smtp_host']),
                'smtp_port' => (int) $data['smtp_port'],
                'smtp_encryption' => $data['smtp_encryption'],
                'smtp_username' => trim((string) $data['smtp_username']),
            ];
            if (trim((string) ($data['smtp_password'] ?? '')) !== '') {
                $settings['smtp_password'] = (string) $data['smtp_password'];
            }
        }
        $channel->fill(['settings' => $settings, 'active' => true, 'updated_by' => $request->user()->id])->save();

        return response()->json(['success' => true, 'message' => $own ? 'Email settings saved. Send yourself a test email to check the account.' : 'Email settings saved.']);
    }

    /** Send a test email to the admin through the organisation's settings (its own account, or the app's). */
    public function test(Request $request, string $uid): JsonResponse
    {
        $organisation = $this->organisation($request, $uid);
        $channel = NotificationChannel::for((int) $organisation->id, 'email');
        [$mailer, $fromAddress] = OrganisationMailer::for((int) $organisation->id);
        [, $fromName, $replyTo] = SendNotification::sender((int) $organisation->id);
        $to = (string) $request->user()->email;
        $via = $channel && OrganisationMailer::usesOwnAccount($channel) ? 'your own email account ('.$fromAddress.')' : 'the app\'s email account';
        $html = SendNotification::html((int) $organisation->id, [
            'subject' => 'Test email from {{organisation}}',
            'body' => "This is a test email from **{{organisation}}**, sent through {{via}}.\n\nIf you can read this, emails to your members will arrive the same way.",
        ], ['organisation' => $organisation->name, 'via' => $via]);

        try {
            $mailer->html($html, function ($message) use ($to, $fromAddress, $fromName, $replyTo, $organisation) {
                $message->to($to)->subject('Test email from '.$organisation->name)->from($fromAddress, $fromName);
                if ($replyTo) {
                    $message->replyTo($replyTo, $fromName);
                }
            });
        } catch (\Throwable $e) {
            $channel?->forceFill(['tested_at' => now(), 'test_result' => mb_substr('Failed: '.$e->getMessage(), 0, 255)])->save();

            return response()->json(['success' => false, 'message' => 'The test email could not be sent: '.$e->getMessage()], 422);
        }
        $channel?->forceFill(['tested_at' => now(), 'test_result' => 'Sent to '.$to])->save();

        return response()->json(['success' => true, 'message' => "Test email sent to {$to} through {$via}. Check the inbox (and Spam)."]);
    }

    private function organisation(Request $request, string $uid): Organisation
    {
        $organisation = Organisation::query()->where('uid', $uid)->firstOrFail();
        abort_unless(NotificationAccess::canManage($request->user(), (int) $organisation->id), 404);

        return $organisation;
    }
}
