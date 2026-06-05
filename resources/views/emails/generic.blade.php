@php
    $color = $brand['color'] ?? '#f97316';
    $appName = $brand['display_name'] ?? ($brand['name'] ?? 'SmartPRS');
    $logo = $brand['logo'] ?? '';
    $tagline = $brand['tagline'] ?? '';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $heading }}</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08);">
                    <!-- Header -->
                    <tr>
                        <td style="background:{{ $color }};padding:20px 28px;">
                            @if ($logo)
                                <img src="{{ $logo }}" alt="{{ $appName }}" height="34" style="max-height:34px;vertical-align:middle;">
                            @else
                                <span style="color:#ffffff;font-size:20px;font-weight:bold;letter-spacing:.3px;">{{ $appName }}</span>
                            @endif
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding:28px;">
                            <h1 style="margin:0 0 14px;font-size:20px;color:#0f172a;">{{ $heading }}</h1>

                            @if (!empty($toName))
                                <p style="margin:0 0 12px;font-size:14px;color:#334155;">Hi {{ $toName }},</p>
                            @endif

                            @if (!empty($intro))
                                <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#334155;">{{ $intro }}</p>
                            @endif

                            @if (!empty($lines))
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:0 0 18px;">
                                    @foreach ($lines as $label => $value)
                                        <tr>
                                            <td style="padding:8px 10px;font-size:13px;color:#64748b;border-bottom:1px solid #e2e8f0;width:42%;">{{ $label }}</td>
                                            <td style="padding:8px 10px;font-size:13px;color:#0f172a;font-weight:bold;border-bottom:1px solid #e2e8f0;">{{ $value }}</td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif

                            @if (!empty($bodyText))
                                <p style="margin:0 0 18px;font-size:14px;line-height:1.6;color:#334155;">{{ $bodyText }}</p>
                            @endif

                            @if (!empty($ctaUrl) && !empty($ctaLabel))
                                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:6px 0 8px;">
                                    <tr>
                                        <td style="border-radius:6px;background:{{ $color }};">
                                            <a href="{{ $ctaUrl }}" style="display:inline-block;padding:11px 22px;font-size:14px;color:#ffffff;text-decoration:none;font-weight:bold;border-radius:6px;">{{ $ctaLabel }}</a>
                                        </td>
                                    </tr>
                                </table>
                            @endif
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="padding:18px 28px;background:#f8fafc;border-top:1px solid #e2e8f0;">
                            <p style="margin:0;font-size:12px;color:#94a3b8;line-height:1.5;">
                                {{ $tagline ?: 'This is an automated message from '.$appName.'.' }}<br>
                                Please do not reply to this email.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
