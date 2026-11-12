<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordChangedNotification extends Notification
{
    use Queueable;

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your LIB Monitoring System password was changed')
            ->line('Your password was changed successfully.')
            ->line('For your security, you were signed out on all other devices.')
            ->line('If you did not make this change, contact the Program Leader immediately.');
    }
}
