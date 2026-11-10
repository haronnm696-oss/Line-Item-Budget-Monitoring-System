<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountRejectedNotification extends Notification
{
    use Queueable;

    public function __construct(public User $user, public string $reason)
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your LIB Monitoring System registration was not approved')
            ->line('Your registration request was reviewed and was not approved.')
            ->line('Reason: ' . $this->reason)
            ->line('If you believe this is a mistake, contact the Program Leader.');
    }
}
