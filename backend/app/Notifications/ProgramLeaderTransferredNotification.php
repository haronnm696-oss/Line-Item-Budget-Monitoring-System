<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to both people when the Program Leader role is transferred: the
 * previous Program Leader (now deactivated) and the new one. In-app entry
 * plus email.
 */
class ProgramLeaderTransferredNotification extends Notification
{
    use Queueable;

    /**
     * @param array<int, string> $removedProjects titles of the projects the new Program Leader left
     */
    public function __construct(public User $from, public User $to, public array $removedProjects = [])
    {
    }

    public function via($notifiable): array
    {
        // Database first, so the in-app entry is stored even if the mailer fails.
        return ['database', 'mail'];
    }

    protected function isNewLeader($notifiable): bool
    {
        return (int) $notifiable->getKey() === (int) $this->to->id;
    }

    public function toArray($notifiable): array
    {
        return [
            'kind' => 'account',
            'event' => 'program_leader_transferred',
            'title' => 'Program Leader role transferred',
            'message' => $this->isNewLeader($notifiable)
                ? $this->from->name . ' transferred the Program Leader role to you.'
                : 'You transferred the Program Leader role to ' . $this->to->name . '. Your account is now deactivated.',
            'link' => $this->isNewLeader($notifiable) ? '/program-dashboard' : null,
            'from_user_id' => (int) $this->from->id,
            'to_user_id' => (int) $this->to->id,
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $base = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');
        $mail = (new MailMessage)->subject('LIB Monitoring System: Program Leader role transferred');

        if ($this->isNewLeader($notifiable)) {
            $mail->line($this->from->name . ' transferred the Program Leader role to you. You now oversee every project.');
            foreach ($this->removedProjects as $title) {
                $mail->line('You were removed from this project\'s team: ' . $title);
            }

            return $mail->action('Sign in', $base . '/login');
        }

        return $mail
            ->line('You transferred the Program Leader role to ' . $this->to->name . '.')
            ->line('Your account is now deactivated and you were signed out.')
            ->line('If you did not make this change, contact ' . $this->to->name . ' immediately.');
    }
}
