<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to every active Program Leader when someone submits Create account:
 * one in-app notification (the bell) plus the email. Once any Program Leader
 * approves or rejects the request, resolveFor() marks it done for all of them.
 */
class NewRegistrationPendingNotification extends Notification
{
    use Queueable;

    public function __construct(public User $user)
    {
    }

    public function via($notifiable): array
    {
        // Database first, so the bell entry is stored even if the mailer fails.
        return ['database', 'mail'];
    }

    public function toArray($notifiable): array
    {
        $unit = $this->user->college ?: 'No college/unit given';

        return [
            'kind' => 'registration',
            'event' => 'submitted',
            'title' => 'New account request',
            'message' => 'New account request: ' . $this->user->name . ', ' . $unit,
            'link' => '/program-users?review=' . $this->user->id,
            'registration_user_id' => (int) $this->user->id,
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $frontendUrl = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');

        return (new MailMessage)
            ->subject('New LIB Monitoring System registration pending approval')
            ->line('A new registration has been submitted and is awaiting review.')
            ->line($this->user->name . ' (' . $this->user->email . ')')
            ->line('Position: ' . ($this->user->position ?: 'Not specified'))
            ->action('Review the request', $frontendUrl . '/program-users?review=' . $this->user->id);
    }

    /** Mark this applicant's request notification as done for every Program Leader. */
    public static function resolveFor(User $applicant): void
    {
        DatabaseNotification::query()
            ->where('type', self::class)
            ->whereNull('read_at')
            ->where('data->registration_user_id', (int) $applicant->id)
            ->update(['read_at' => now()]);
    }
}
