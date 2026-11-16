<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * In-app notification for a hand-off on payroll, purchase requests and
 * budget requests (stored in the notifications table). The data carries at
 * least kind, event, title, message and link, like ExpenseWorkflowNotification.
 */
class WorkflowNotification extends Notification
{
    public function __construct(private array $data)
    {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return $this->data;
    }
}
