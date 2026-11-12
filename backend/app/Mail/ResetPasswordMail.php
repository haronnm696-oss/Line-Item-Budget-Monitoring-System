<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $token;
    public string $email;
    public string $resetUrl;
    public ?string $logoUrl;
    public int $expiresInMinutes;

    public function __construct(string $token, string $email)
    {
        $this->token = $token;
        $this->email = $email;

        $frontendUrl = rtrim((string) config('app.frontend_url', ''), '/');
        $this->resetUrl = $frontendUrl
            . '/reset-password?token=' . urlencode($token)
            . '&email=' . urlencode($email);
        $this->logoUrl = stripos($frontendUrl, 'https://') === 0
            ? $frontendUrl . '/images/msu-seal-outline.png'
            : null;
        $this->expiresInMinutes = (int) config('auth.passwords.users.expire', 60);

        $this->subject('Reset your LIB Monitoring System password');
    }

    public function build()
    {
        return $this->view('emails.reset-password')
            ->text('emails.reset-password-text')
            ->with([
                'resetUrl' => $this->resetUrl,
                'logoUrl' => $this->logoUrl,
                'expiresInMinutes' => $this->expiresInMinutes,
            ]);
    }
}
