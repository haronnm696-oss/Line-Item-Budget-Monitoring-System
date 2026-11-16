<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ExpenseWorkflowNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/** In-app notifications: each user sees and marks only their own. */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowFixtures();
    }

    public function test_list_is_latest_first_paginated_and_counts_unread(): void
    {
        $expense = $this->expenseIn('submitted');
        foreach (range(1, 22) as $i) {
            Carbon::setTestNow(now()->addMinute());
            $this->projectLeader->notify(new ExpenseWorkflowNotification($expense, 'submitted', $this->assistant));
        }
        Carbon::setTestNow(now()->addMinute());
        $this->projectLeader->notify(new ExpenseWorkflowNotification($expense, 'returned', $this->assistant, 'Why'));
        Carbon::setTestNow();
        // Someone else's notification never shows up.
        $this->finance->notify(new ExpenseWorkflowNotification($expense, 'endorsed', $this->projectLeader));

        $page = $this->actingAs($this->projectLeader)->getJson('/api/notifications')->assertOk();
        $page->assertJsonCount(20, 'notifications')
            ->assertJsonPath('meta.total', 23)
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('meta.unread', 23)
            ->assertJsonPath('notifications.0.event', 'returned')
            ->assertJsonPath('notifications.0.read', false)
            ->assertJsonPath('notifications.0.link', "/expenses?project={$this->project->id}&expense={$expense->id}");

        $this->getJson('/api/notifications?page=2')->assertOk()->assertJsonCount(3, 'notifications')->assertJsonPath('meta.has_more', false);
        $this->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('unread', 23);
    }

    public function test_mark_one_and_mark_all_read_touch_only_the_users_own(): void
    {
        $expense = $this->expenseIn('submitted');
        $this->projectLeader->notify(new ExpenseWorkflowNotification($expense, 'submitted', $this->assistant));
        $this->projectLeader->notify(new ExpenseWorkflowNotification($expense, 'submitted', $this->assistant));
        $this->finance->notify(new ExpenseWorkflowNotification($expense, 'endorsed', $this->projectLeader));

        $mine = $this->projectLeader->notifications()->first();
        $theirs = $this->finance->notifications()->first();

        $this->actingAs($this->projectLeader);
        $this->patchJson("/api/notifications/{$theirs->id}/read")->assertNotFound();
        $this->patchJson("/api/notifications/{$mine->id}/read")->assertOk()->assertJsonPath('notification.read', true);
        $this->getJson('/api/notifications/unread-count')->assertJsonPath('unread', 1);

        $this->patchJson('/api/notifications/read-all')->assertOk()->assertJsonPath('unread', 0);
        $this->getJson('/api/notifications/unread-count')->assertJsonPath('unread', 0);
        $this->assertSame(1, $this->finance->unreadNotifications()->count());
    }

    public function test_notifications_need_a_signed_in_active_account(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
        $this->getJson('/api/notifications/unread-count')->assertUnauthorized();

        $this->projectLeader->forceFill(['is_active' => false])->save();
        $this->actingAs(User::findOrFail($this->projectLeader->id))->getJson('/api/notifications')->assertUnauthorized();
    }
}
