<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset your LIB Monitoring System password</title>
</head>
<body style="margin:0;padding:0;background-color:#F3ECE1;font-family:Inter,system-ui,-apple-system,'Segoe UI',Arial,sans-serif;color:#2B2023;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F3ECE1;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background:#FFFFFF;border-radius:12px;overflow:hidden;">
                    <tr>
                        <td style="background-color:#6F1D2A;padding:24px;text-align:center;color:#FAF7F2;">
                            @if($logoUrl)
                                <img src="{{ $logoUrl }}" alt="Mindanao State University seal" height="56" style="display:block;height:56px;width:auto;margin:0 auto 12px;border:0;outline:none;text-decoration:none;">
                            @endif
                            <div style="font-size:20px;font-weight:700;line-height:1.3;color:#FAF7F2;">LIB Monitoring System</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px 28px;">
                            <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;color:#6F1D2A;">Reset your password</h1>
                            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#2B2023;">We received a request to reset the password for your LIB Monitoring System account.</p>
                            <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#2B2023;">Click the button below to choose a new password.</p>
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
                                <tr>
                                    <td align="center" style="border-radius:6px;background-color:#6F1D2A;">
                                        <a href="{{ $resetUrl }}" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:600;line-height:1.2;color:#FFFFFF;text-decoration:none;border-radius:6px;">Reset password</a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:0 0 16px;font-size:13px;line-height:1.6;color:#6B5F5F;">This link expires in {{ $expiresInMinutes }} minutes and can be used only once.</p>
                            <p style="margin:0;font-size:13px;line-height:1.6;color:#6B5F5F;">If you did not request this, you can ignore this email. Your password will not change.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 28px;background-color:#FAF7F2;text-align:center;font-size:12px;line-height:1.5;color:#6B5F5F;">MSU–Main Campus · Authorized personnel only</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
