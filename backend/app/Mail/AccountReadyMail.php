<?php

namespace App\Mail;

use App\Support\MailBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when a Program Leader adds a user. It carries no token: the person
 * sets a password through Forgot password with an emailed 6-digit code.
 */
class AccountReadyMail extends Mailable
{
    use Queueable, SerializesModels;

    public const SUBJECT = 'Your LIB Monitoring System account is ready';

    public string $name;
    public string $email;
    public string $roleLabel;
    public string $forgotPasswordUrl;
    public ?string $logoUrl;

    public function __construct(string $name, string $email, string $roleLabel)
    {
        $this->name = $name;
        $this->email = $email;
        $this->roleLabel = $roleLabel;
        $this->forgotPasswordUrl = MailBranding::frontendUrl() . '/forgot-password';
        $this->logoUrl = MailBranding::logoUrl();

        $this->subject(self::SUBJECT);
    }

    public function build()
    {
        return $this->view('emails.account-ready')
            ->text('emails.account-ready-text');
    }
}
