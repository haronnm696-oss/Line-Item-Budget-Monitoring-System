<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountSetupNotification extends Notification
{
    use Queueable;

    public function __construct(public User $user, public string $setupUrl)
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Set up your LIB Monitoring System password')
            ->line('A Program Leader created an account for you.')
            ->line('Choose your password using the button below. The link expires in 60 minutes.')
            ->action('Set your password', $this->setupUrl)
            ->line('If you did not expect this email, contact the Program Leader.');
    }
}
