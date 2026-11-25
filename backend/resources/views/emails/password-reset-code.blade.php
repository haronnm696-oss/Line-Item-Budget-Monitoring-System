@extends('emails.layouts.lib')

@section('title', 'Your LIB Monitoring System password reset code')

@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;color:#6F1D2A;">Your password reset code</h1>
    <p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#2B2023;">Enter this code on the LIB Monitoring System page where you asked to reset your password.</p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
        <tr>
            <td align="center" style="background-color:#FAF7F2;border:1px solid #E6DCCB;border-radius:10px;padding:20px 12px;">
                <div style="font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,monospace;font-size:36px;font-weight:700;letter-spacing:10px;line-height:1.2;color:#6F1D2A;">{{ $code }}</div>
            </td>
        </tr>
    </table>
    <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#2B2023;">This code expires in {{ $expiresInMinutes }} minutes and can be used only once. Never share it with anyone.</p>
    <p style="margin:0;font-size:13px;line-height:1.6;color:#6B5F5F;">If you did not request this, ignore this email. Your password will not change.</p>
@endsection
