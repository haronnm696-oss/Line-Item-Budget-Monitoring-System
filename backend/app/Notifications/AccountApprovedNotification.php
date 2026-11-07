<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class AccountApprovedNotification extends Notification
{
    use Queueable;

    public function __construct(public User $user, public string $roleName)
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your LIB Monitoring System account has been approved')
            ->line('Your account has been approved and you can now sign in.')
            ->line('Assigned role: ' . $this->roleName);
    }
}
