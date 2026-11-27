<?php

namespace Iquesters\Notifications\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Iquesters\Foundation\System\Http\ApiResponse;
use Iquesters\Notifications\Models\NotificationLog;
use Iquesters\Notifications\Support\NotificationAccess;
use Iquesters\Notifications\Support\NotificationTypes;
use Iquesters\Notifications\Support\Resender;

/** Sent messages (generic table notification-log-table): what went out, to whom, and what happened. */
class LogController extends Controller
{
    private const TONES = [
        NotificationLog::STATUS_SENT => 'success',
        NotificationLog::STATUS_QUEUED => 'info',
        NotificationLog::STATUS_FAILED => 'danger',
        NotificationLog::STATUS_SKIPPED => 'secondary',
    ];

    public function data(Request $request): JsonResponse
    {
        $query = $this->scoped($request);
        if (in_array($status = (string) $request->query('status', ''), array_keys(NotificationLog::LABELS), true)) {
            $query->where('status', $status);
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('recipient', 'like', $like)->orWhere('recipient_name', 'like', $like)->orWhere('subject', 'like', $like)->orWhere('type', 'like', $like));
        }
        $offset = max(0, (int) $request->query('offset', 0));
        $length = min(100, max(1, (int) $request->query('length', 25)));
        $total = (clone $query)->count();
        $rows = $query->latest('id')->skip($offset)->take($length)->get()->map(fn (NotificationLog $log) => $this->row($log))->all();

        return ApiResponse::success($rows, 'OK', 200, ['pagination' => ['total' => $total, 'offset' => $offset, 'length' => $length]]);
    }

    public function record(Request $request, string $uid): JsonResponse
    {
        return ApiResponse::success($this->row($this->scoped($request)->where('uid', $uid)->firstOrFail()));
    }

    /** What happened to this message (the record's Details section). */
    public function summary(Request $request, string $uid): View
    {
        $log = $this->scoped($request)->where('uid', $uid)->firstOrFail();

        return view('notifications::admin.log-summary', ['row' => $this->row($log)]);
    }

    /** Send a failed message again (one try, now). */
    public function resend(Request $request, string $uid): JsonResponse
    {
        $log = $this->scoped($request)->where('uid', $uid)->firstOrFail();
        if (! Resender::canResend($log)) {
            return response()->json(['success' => false, 'message' => $log->status === NotificationLog::STATUS_FAILED
                ? 'This message can\'t be sent again: it held a one-time code. The person asks for a new code instead.'
                : 'Only a message that failed can be sent again.'], 422);
        }
        if (Resender::resend($log)) {
            return response()->json(['success' => true, 'message' => 'Sent to '.$log->recipient.'.', 'data' => $this->row($log->fresh())]);
        }

        return response()->json(['success' => false, 'message' => 'Still not sent: '.$log->fresh()->error], 422);
    }

    /** The organisations managed (the one worked in, when chosen); super-admins also the platform's own messages. */
    private function scoped(Request $request): Builder
    {
        $user = $request->user();
        $working = NotificationAccess::workingOrganisationId($user);
        $ids = $working ? [$working] : NotificationAccess::organisationIds($user);

        return NotificationLog::query()->where(fn (Builder $q) => $q->whereIn('organisation_id', $ids)
            ->when(NotificationAccess::isPlatformAdmin($user) && ! $working, fn ($q) => $q->orWhereNull('organisation_id')));
    }

    private function row(NotificationLog $log): array
    {
        $type = app(NotificationTypes::class)->get($log->type);

        return [
            'uid' => $log->uid,
            'name' => $log->recipient_name ?: $log->recipient,
            'recipient' => $log->recipient,
            'recipient_name' => $log->recipient_name,
            'message' => $type['label'] ?? $log->type,
            'channel' => ucfirst($log->channel),
            'subject' => $log->subject,
            'status' => $log->status,
            'status_label' => $log->statusLabel(),
            'status_tone' => self::TONES[$log->status] ?? 'secondary',
            'error' => $log->error,
            'attempts' => $log->attempts,
            'can_resend' => Resender::canResend($log) ? '1' : '0',
            'when' => ($log->sent_at ?? $log->created_at)?->format('j M Y, g:i A'),
        ];
    }
}
