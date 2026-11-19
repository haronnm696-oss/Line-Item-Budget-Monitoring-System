<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/** In-app notifications of the signed-in user only. */
class NotificationController extends Controller
{
    public const PER_PAGE = 20;

    /** GET /api/notifications?page= — latest first. */
    public function index(Request $request)
    {
        $page = $request->user()->notifications()->latest()->paginate(self::PER_PAGE);

        return response()->json([
            'notifications' => collect($page->items())->map(fn (DatabaseNotification $n) => $this->row($n))->values(),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'has_more' => $page->hasMorePages(),
                'unread' => $request->user()->unreadNotifications()->count(),
            ],
        ]);
    }

    public function unreadCount(Request $request)
    {
        return response()->json(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function markRead(Request $request, string $id)
    {
        // Only the user's own notifications can be found here (404 otherwise).
        $notification = $request->user()->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        return response()->json(['notification' => $this->row($notification->fresh())]);
    }

    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['unread' => 0]);
    }

    private function row(DatabaseNotification $notification): array
    {
        $data = $notification->data ?? [];

        return [
            'id' => $notification->id,
            'kind' => $data['kind'] ?? null,
            'event' => $data['event'] ?? null,
            'title' => $data['title'] ?? 'Notification',
            'message' => $data['message'] ?? '',
            'link' => $data['link'] ?? null,
            // The record it is about, so the bell can open it ({type, id} or null).
            'project_id' => isset($data['project_id']) ? (int) $data['project_id'] : null,
            'record' => $this->record($data),
            'read' => $notification->read_at !== null,
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }

    private function record(array $data): ?array
    {
        foreach ([
            'expense_id' => 'expense',
            'payroll_batch_id' => 'payroll',
            'purchase_request_id' => 'purchase_request',
            'approval_id' => 'approval',
            // New account request: opens that applicant's Review panel.
            'registration_user_id' => 'registration',
        ] as $field => $type) {
            if (! empty($data[$field])) {
                return ['type' => $type, 'id' => (int) $data[$field]];
            }
        }

        return null;
    }
}
