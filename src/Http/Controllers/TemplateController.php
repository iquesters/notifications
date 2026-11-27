<?php

namespace Iquesters\Notifications\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Iquesters\Foundation\System\Http\ApiResponse;
use Iquesters\Notifications\Jobs\SendNotification;
use Iquesters\Notifications\Models\NotificationTemplate;
use Iquesters\Notifications\Support\NotificationAccess;
use Iquesters\Notifications\Support\NotificationTypes;
use Iquesters\Notifications\Support\Renderer;
use Iquesters\Notifications\Support\Templates;

/**
 * Templates (generic table notification-template-table): the messages of the
 * installed modules for the organisation worked in (a super-admin without one:
 * the platform's defaults). Edit the wording, preview it, send a test, reset.
 */
class TemplateController extends Controller
{
    public function data(Request $request): JsonResponse
    {
        $organisationId = NotificationAccess::workingOrganisationId($request->user());
        Templates::ensureFor($organisationId);
        $types = app(NotificationTypes::class);
        $rows = NotificationTemplate::query()
            ->where('organisation_id', $organisationId)
            ->orderBy('type')->orderBy('channel')
            ->get()
            // An organisation's list: its own messages; the platform's (super-admins): all, app messages too.
            ->filter(fn (NotificationTemplate $t) => $types->has($t->type) && ($organisationId === null || ! $types->get($t->type)['platform']))
            ->when(trim((string) $request->query('search', '')) !== '', function ($rows) use ($request, $types) {
                $q = mb_strtolower(trim((string) $request->query('search')));

                return $rows->filter(fn ($t) => str_contains(mb_strtolower($types->get($t->type)['label'].' '.$t->type.' '.$t->subject), $q));
            })
            ->map(fn (NotificationTemplate $t) => $this->row($t))
            ->values();
        $offset = max(0, (int) $request->query('offset', 0));
        $length = min(100, max(1, (int) $request->query('length', 25)));

        return ApiResponse::success($rows->slice($offset, $length)->values()->all(), 'OK', 200, ['pagination' => ['total' => $rows->count(), 'offset' => $offset, 'length' => $length]]);
    }

    public function record(Request $request, string $uid): JsonResponse
    {
        return ApiResponse::success($this->row($this->find($request, $uid)));
    }

    public function update(Request $request, string $uid): JsonResponse
    {
        $template = $this->find($request, $uid);
        $data = $request->validate([
            'active' => ['nullable', 'boolean'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
        ]);
        $template->update([
            // A required message (e.g. the sign-in code) stays on.
            'active' => $request->boolean('active') || (bool) (app(NotificationTypes::class)->get($template->type)['required'] ?? false),
            'subject' => trim($data['subject']),
            'body' => trim($data['body']),
            // Their own wording from now on (the default no longer replaces it).
            'customised' => true,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json(['success' => true, 'message' => 'Wording saved.', 'data' => $this->row($template->fresh())]);
    }

    /** Record section: the placeholders this message has, and the email filled with sample values. */
    public function preview(Request $request, string $uid): View
    {
        $template = $this->find($request, $uid);
        $type = app(NotificationTypes::class)->get($template->type);
        $values = (array) ($type['sample'] ?? []);

        return view('notifications::admin.preview', [
            'template' => $template,
            'type' => $type,
            'subject' => Renderer::text($template->subject, $values),
            'html' => SendNotification::html($template->organisation_id, ['subject' => $template->subject, 'body' => $template->body], $values),
        ]);
    }

    /** Send the message, filled with the sample values, to the admin's own address. */
    public function test(Request $request, string $uid): JsonResponse
    {
        $template = $this->find($request, $uid);
        $type = app(NotificationTypes::class)->get($template->type);
        $values = (array) ($type['sample'] ?? []);
        $content = ['subject' => $template->subject, 'body' => $template->body];
        $subject = '[Test] '.SendNotification::subject($content, $values);
        [, $fromName, $replyTo] = SendNotification::sender($template->organisation_id);
        $to = (string) $request->user()->email;

        try {
            // Through the account the real emails use (the organisation's own, or the app's).
            [$mailer, $fromAddress] = \Iquesters\Notifications\Support\OrganisationMailer::for($template->organisation_id);
            $mailer->html(SendNotification::html($template->organisation_id, $content, $values), function ($message) use ($to, $subject, $fromName, $replyTo, $fromAddress) {
                $message->to($to)->subject($subject)->from($fromAddress, $fromName);
                if ($replyTo) {
                    $message->replyTo($replyTo, $fromName);
                }
            });
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Could not send: '.$e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => "Test sent to {$to}."]);
    }

    /** Use the default wording again (an organisation: the platform's; the platform: the module's). */
    public function reset(Request $request, string $uid): JsonResponse
    {
        $template = $this->find($request, $uid);
        if ($template->organisation_id) {
            $default = NotificationTemplate::query()->whereNull('organisation_id')->where('type', $template->type)->where('channel', $template->channel)->first();
            $values = ['subject' => $default?->subject, 'body' => (string) $default?->body];
        } else {
            $values = app(NotificationTypes::class)->get($template->type)['defaults'][$template->channel] ?? [];
            $values = ['subject' => $values['subject'] ?? null, 'body' => (string) ($values['body'] ?? '')];
        }
        $template->update($values + ['customised' => false, 'updated_by' => $request->user()->id]);

        return response()->json(['success' => true, 'message' => 'The default wording is used again.', 'data' => $this->row($template->fresh())]);
    }

    private function find(Request $request, string $uid): NotificationTemplate
    {
        $template = NotificationTemplate::query()->where('uid', $uid)->firstOrFail();
        abort_unless(NotificationAccess::canManage($request->user(), $template->organisation_id ? (int) $template->organisation_id : null), 404);

        return $template;
    }

    private function row(NotificationTemplate $template): array
    {
        $type = app(NotificationTypes::class)->get($template->type) ?? ['label' => $template->type, 'description' => '', 'module' => ''];

        return [
            'uid' => $template->uid,
            'name' => $type['label'],
            'label' => $type['label'],
            'description' => $type['description'],
            'module' => ucfirst((string) $type['module']),
            'type' => $template->type,
            'channel' => ucfirst($template->channel),
            'subject' => $template->subject,
            'body' => $template->body,
            'active' => $template->active,
            'status_label' => $template->active ? 'On' : 'Off',
            'wording' => $template->customised ? ($template->organisation_id ? 'Your own wording' : 'Changed default') : 'Default wording',
            'customised' => $template->customised ? '1' : '0',
        ];
    }
}
