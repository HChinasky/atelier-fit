<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('mail.login_code.title') }}</title>
</head>
{{-- Table layout and inline styles: mail clients ignore most of everything
     else. The palette is the site's four greys. --}}
<body style="margin:0;padding:0;background:#F0F0F0;font-family:Helvetica,Arial,sans-serif;color:#2D2D2D;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F0F0F0;">
        <tr>
            <td align="center" style="padding:40px 16px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:440px;background:#FFFFFF;">
                    <tr>
                        <td style="padding:40px 32px 36px;text-align:center;">
                            <p style="margin:0 0 28px;font-size:11px;letter-spacing:.2em;text-transform:uppercase;color:#6B6B6B;">Atelier Fit</p>
                            <p style="margin:0 0 10px;font-size:14px;line-height:1.6;color:#6B6B6B;">{{ __('mail.login_code.title') }}</p>
                            <p style="margin:0 0 24px;font-size:34px;letter-spacing:.3em;font-weight:600;color:#2D2D2D;">{{ $code }}</p>
                            <p style="margin:0;font-size:13px;line-height:1.6;color:#6B6B6B;">
                                {{ __('mail.login_code.valid', ['minutes' => $ttlMinutes]) }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
