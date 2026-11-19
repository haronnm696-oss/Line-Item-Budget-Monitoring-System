<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountApprovedNotification extends Notification
{
    use Queueable;

    /**
     * @param array<int, string> $assignments "Project title (Role)" lines, if any
     */
    public function __construct(public User $user, public string $roleName, public array $assignments = [])
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $base = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');

        $mail = (new MailMessage)
            ->subject('Your LIB Monitoring System account was approved')
            ->line('Your account was approved; you can now sign in.')
            ->line('Account role: ' . $this->roleName);

        foreach ($this->assignments as $line) {
            $mail->line('Project: ' . $line);
        }

        return $mail->action('Sign in', $base . '/login');
    }
}
