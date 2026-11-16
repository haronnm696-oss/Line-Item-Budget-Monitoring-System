<?php

namespace App\Notifications;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Notifications\Notification;

/** In-app notification for an expense hand-off (stored in the notifications table). */
class ExpenseWorkflowNotification extends Notification
{
    public function __construct(
        private Expense $expense,
        private string $event,
        private User $actor,
        private ?string $reason = null
    ) {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        $expense = $this->expense;
        $what = trim(implode(' · ', array_filter([$expense->reference_no, $expense->description])));
        $amount = '₱' . number_format((float) $expense->amount, 2);
        $actor = $this->actor->name;
        $reason = $this->reason ? ' Reason: ' . $this->reason : '';

        $message = match ($this->event) {
            'submitted' => "{$actor} submitted an expense for your approval: {$what} ({$amount}).",
            'endorsed' => "{$actor} (Project Leader) approved an expense; it is ready for verification: {$what} ({$amount}).",
            'leader_submitted' => "{$actor} (Project Leader) recorded and submitted an expense; it is ready for verification: {$what} ({$amount}).",
            'returned' => "{$actor} returned your expense: {$what}.{$reason}",
            'rejected' => "{$actor} rejected your expense and sent it back: {$what}.{$reason}",
            'verified' => "{$actor} verified your expense: {$what} ({$amount}).",
            'late_receipt' => "{$actor} uploaded the missing receipt for a verified expense: {$what} ({$amount}).",
            default => "{$actor} updated an expense: {$what}.",
        };

        $title = match ($this->event) {
            'submitted' => 'Expense waiting for your approval',
            'endorsed', 'leader_submitted' => 'Expense ready for verification',
            'returned' => 'Expense returned to you',
            'rejected' => 'Expense rejected',
            'verified' => 'Expense verified',
            'late_receipt' => 'Missing receipt uploaded',
            default => 'Expense updated',
        };

        return [
            'kind' => 'expense',
            'event' => $this->event,
            'title' => $title,
            'message' => $message,
            'expense_id' => $expense->id,
            'project_id' => $expense->project_id,
            'project' => $expense->project?->title,
            'actor' => $actor,
            'reason' => $this->reason,
            'link' => '/expenses?project=' . $expense->project_id . '&expense=' . $expense->id,
        ];
    }
}
