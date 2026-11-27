@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make(['Email settings', $organisation->name]))

@section('content')
<div class="d-grid gap-3">
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                <div>
                    <h5 class="fs-6 mb-1">Email settings of {{ $organisation->name }}</h5>
                    <p class="text-muted small mb-0">The emails people get from this organisation (registration, payment, membership).</p>
                </div>
                @if ($organisations->count() > 1)
                    <select class="form-select form-select-sm w-auto" aria-label="Organisation" onchange="if (this.value) window.location = this.value">
                        @foreach ($organisations as $org)
                            <option value="{{ route('notifications.settings.edit', $org->uid) }}" @selected($org->uid === $organisation->uid)>{{ $org->name }}</option>
                        @endforeach
                    </select>
                @endif
            </div>

            {{-- Form schema notification-email-settings; entity_uid: the organisation. Read-only first, Edit switches. --}}
            @include('userinterface::components.form', [
                'id' => $formId,
                'entity_uid' => $organisation->uid,
                'form_mode' => 'view',
                'formData' => json_encode($formData, JSON_UNESCAPED_UNICODE),
            ])
        </div>
    </div>

    {{-- Check the settings: a test email to the admin, through the account the real emails use. --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <h6 class="fs-6 mb-1">Send a test email</h6>
            <p class="small text-muted mb-3">
                Sends an email to you ({{ auth()->user()->email }}) through the saved settings, so you can see it arrives and how it looks.
                @if ($lastTest)
                    <br>Last test {{ $lastTest['at']->format('j M Y, g:i A') }}:
                    <span class="{{ str_starts_with((string) $lastTest['result'], 'Failed') ? 'text-danger' : 'text-success' }}">{{ $lastTest['result'] }}</span>
                @endif
            </p>
            {{-- Form schema notification-email-test (a button); entity_uid: the organisation. --}}
            @include('userinterface::components.form', ['id' => $testFormId, 'entity_uid' => $organisation->uid, 'formData' => ''])
        </div>
    </div>
</div>
@endsection
