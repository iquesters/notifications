{{--
    The email every notification is sent in. The header says who it is from: an
    organisation's message shows its logo and name (its logo missing: the app's);
    an app message (e.g. a sign-in code) shows the app's logo and name.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject }}</title>
    <style>
        /* A heading in a message (e.g. "## 327930", a sign-in code) stands out in a soft box. */
        h2 { display: inline-block; margin: 6px 0 18px; padding: 12px 22px; border-radius: 10px; background: #f1f3f7;
             font-family: Consolas, Menlo, monospace; font-size: 30px; letter-spacing: 8px; color: #111827; }
        p { margin: 0 0 14px; }
        a { color: #2563eb; }
    </style>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2328;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
    <tr><td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;">
            <tr><td style="padding:20px 24px;border-bottom:1px solid #eceef1;">
                @if ($logoUrl)<img src="{{ $logoUrl }}" alt="{{ $brandName }}" height="36" style="height:36px;max-width:180px;vertical-align:middle;margin-right:10px;border-radius:6px;">@endif
                <span style="font-weight:600;font-size:16px;vertical-align:middle;">{{ $brandName }}</span>
            </td></tr>
            <tr><td style="padding:24px;font-size:15px;line-height:1.55;">
                {!! $body !!}
            </td></tr>
            <tr><td style="padding:16px 24px;border-top:1px solid #eceef1;font-size:12px;color:#6b7280;">
                @if ($organisation)
                    Sent by {{ $organisation->name }} via {{ $appName }}.
                @else
                    Sent by {{ $appName }}.
                @endif
                @if (! empty($credit))
                    <div style="margin-top:6px;">
                        @if ($credit['url'])<a href="{{ $credit['url'] }}" style="color:#6b7280;text-decoration:underline;">{{ $credit['text'] }}</a>@else{{ $credit['text'] }}@endif
                    </div>
                @endif
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
