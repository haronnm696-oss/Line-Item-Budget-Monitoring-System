<?php

namespace App\Notifications;

use App\Mail\PasswordResetCodeMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Sent synchronously (not queued) so the code is never written to a jobs table. */
class PasswordResetCodeNotification extends Notification
{
    use Queueable;

    public function __construct(public string $code)
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new PasswordResetCodeMail($this->code))
            ->to($notifiable->routeNotificationFor('mail', $this));
    }
}
