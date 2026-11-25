<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>
</head>
<body style="margin:0;padding:0;background-color:#F3ECE1;font-family:Inter,system-ui,-apple-system,'Segoe UI',Arial,sans-serif;color:#2B2023;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F3ECE1;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background:#FFFFFF;border-radius:12px;overflow:hidden;">
                    <tr>
                        <td style="background-color:#6F1D2A;padding:24px;text-align:center;color:#FAF7F2;">
                            @if($logoUrl)
                                <img src="{{ $logoUrl }}" alt="LIB Monitoring System" width="198" height="48" style="display:block;width:198px;max-width:100%;height:auto;margin:0 auto;border:0;outline:none;text-decoration:none;">
                            @else
                                <div style="font-size:20px;font-weight:700;line-height:1.3;color:#FAF7F2;">LIB Monitoring System</div>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px 28px;">
                            @yield('content')
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
