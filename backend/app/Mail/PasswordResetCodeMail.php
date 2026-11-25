<?php

namespace App\Mail;

use App\Services\PasswordResetCodeService;
use App\Support\MailBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordResetCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public const SUBJECT = 'Your LIB Monitoring System password reset code';

    public string $code;
    public ?string $logoUrl;
    public int $expiresInMinutes;

    public function __construct(string $code)
    {
        $this->code = $code;

        $this->logoUrl = MailBranding::logoUrl();
        $this->expiresInMinutes = PasswordResetCodeService::CODE_TTL_MINUTES;

        $this->subject(self::SUBJECT);
    }

    public function build()
    {
        return $this->view('emails.password-reset-code')
            ->text('emails.password-reset-code-text')
            ->with([
                'code' => $this->code,
                'logoUrl' => $this->logoUrl,
                'expiresInMinutes' => $this->expiresInMinutes,
            ]);
    }
}
