<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class NewRegistrationPendingNotification extends Notification
{
    use Queueable;

    public function __construct(public User $user)
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $frontendUrl = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');

        return (new MailMessage)
            ->subject('New LIB Monitoring System registration pending approval')
            ->line('A new registration has been submitted and is awaiting review.')
            ->line($this->user->name . ' (' . $this->user->email . ')')
            ->line('Position: ' . ($this->user->position ?: 'Not specified'))
            ->action('Review pending requests', $frontendUrl . '/users');
    }
}
