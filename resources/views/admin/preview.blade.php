{{-- Record section (Templates table): what the message is for, its placeholders, and the email filled with sample values. --}}
<div class="d-grid gap-3 small">
    @if ($type)
        <div>
            <div class="fw-semibold">{{ $type['label'] }}</div>
            @if ($type['description'])<div class="text-muted">{{ $type['description'] }}</div>@endif
        </div>
        @if (! empty($type['variables']))
            <div>
                <div class="text-muted mb-1">Placeholders you can use</div>
                <div class="d-flex flex-wrap gap-1">
                    @foreach ($type['variables'] as $name => $meaning)
                        <span class="badge text-bg-light border fw-normal" title="{{ $meaning }}"><span class="font-monospace">{{ str_repeat('{', 2).$name.str_repeat('}', 2) }}</span> · {{ $meaning }}</span>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
    <div>
        <div class="text-muted mb-1">Preview (with sample values)</div>
        <div class="border rounded-3 overflow-hidden">
            <div class="px-3 py-2 bg-body-tertiary border-bottom"><span class="text-muted">Subject:</span> <span class="fw-semibold">{{ $subject }}</span></div>
            {{-- The email as sent, isolated from the page's styles. --}}
            <iframe title="Email preview" srcdoc="{{ $html }}" style="width:100%;height:480px;border:0;background:#f4f5f7;"></iframe>
        </div>
    </div>
</div>
