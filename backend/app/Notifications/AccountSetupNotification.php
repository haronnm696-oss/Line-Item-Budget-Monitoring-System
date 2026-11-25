<?php

namespace App\Notifications;

use App\Mail\AccountReadyMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** "Your account is ready" email for a user a Program Leader added. */
class AccountSetupNotification extends Notification
{
    use Queueable;

    public function __construct(public User $user, public string $roleLabel)
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new AccountReadyMail($this->user->name, $this->user->email, $this->roleLabel))
            ->to($notifiable->routeNotificationFor('mail', $this));
    }
}
