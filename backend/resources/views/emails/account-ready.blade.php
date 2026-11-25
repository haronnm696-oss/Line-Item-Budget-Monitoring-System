@extends('emails.layouts.lib')

@section('title', 'Your LIB Monitoring System account is ready')

@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;color:#6F1D2A;">Your account is ready</h1>
    <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#2B2023;">A Program Leader created a LIB Monitoring System account for <strong>{{ $name }}</strong> with the role <strong>{{ $roleLabel }}</strong>.</p>
    <p style="margin:0 0 8px;font-size:15px;line-height:1.6;color:#2B2023;">To set your password:</p>
    <ol style="margin:0 0 24px;padding-left:22px;font-size:15px;line-height:1.6;color:#2B2023;">
        <li>Open <a href="{{ $forgotPasswordUrl }}" style="color:#6F1D2A;">{{ $forgotPasswordUrl }}</a></li>
        <li>Enter this email address: <strong>{{ $email }}</strong></li>
        <li>Use the 6-digit code we send you, then choose your password.</li>
    </ol>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
        <tr>
            <td align="center" style="border-radius:6px;background-color:#6F1D2A;">
                <a href="{{ $forgotPasswordUrl }}" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:600;line-height:1.2;color:#FFFFFF;text-decoration:none;border-radius:6px;">Set your password</a>
            </td>
        </tr>
    </table>
    <p style="margin:0;font-size:13px;line-height:1.6;color:#6B5F5F;">If you did not expect this email, contact the Program Leader.</p>
@endsection
