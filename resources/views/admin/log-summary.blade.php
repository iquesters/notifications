{{-- Record section (Sent messages): what was sent, to whom, and what happened. --}}
<dl class="row small mb-0">
    <dt class="col-sm-3 text-muted fw-normal">Message</dt>
    <dd class="col-sm-9">{{ $row['message'] }} ({{ $row['channel'] }})</dd>

    <dt class="col-sm-3 text-muted fw-normal">To</dt>
    <dd class="col-sm-9">{{ $row['recipient_name'] ? $row['recipient_name'].' · ' : '' }}{{ $row['recipient'] }}</dd>

    @if ($row['subject'])
        <dt class="col-sm-3 text-muted fw-normal">Subject</dt>
        <dd class="col-sm-9">{{ $row['subject'] }}</dd>
    @endif

    <dt class="col-sm-3 text-muted fw-normal">Status</dt>
    <dd class="col-sm-9"><span class="badge text-bg-{{ $row['status_tone'] }}">{{ $row['status_label'] }}</span>
        <span class="text-muted ms-1">{{ $row['when'] }} · {{ $row['attempts'] }} {{ $row['attempts'] == 1 ? 'try' : 'tries' }}</span></dd>

    @if ($row['error'])
        <dt class="col-sm-3 text-muted fw-normal">Why</dt>
        <dd class="col-sm-9 text-danger">{{ $row['error'] }}</dd>
    @endif
</dl>
@if ($row['status'] === 'failed' && $row['can_resend'] !== '1')
    <div class="small text-muted mt-2">This message held a one-time code, so it isn't kept and can't be sent again. The person can ask for a new code on the sign-in page.</div>
@endif
