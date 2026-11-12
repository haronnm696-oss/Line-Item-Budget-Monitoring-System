<?php

namespace App\Notifications;

use App\Mail\ResetPasswordMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(public string $token)
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        $email = $notifiable->getEmailForPasswordReset();

        return (new ResetPasswordMail($this->token, $email))
            ->to($notifiable->routeNotificationFor('mail', $this));
    }
}
