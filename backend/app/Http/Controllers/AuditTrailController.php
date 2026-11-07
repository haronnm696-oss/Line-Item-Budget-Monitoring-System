<?php

namespace App\Http\Controllers;

use App\Models\AuditTrail;
use Illuminate\Http\Request;

/**
 * Read-only audit log feed for the Audit Trail page. Entries are written by
 * the application and seeders; this controller never mutates them.
 */
class AuditTrailController extends Controller
{
    public function index(Request $request)
    {
        $slug = $request->user()?->primaryRole?->slug;

        abort_unless(
            in_array($slug, ['program-leader', 'project-leader', 'finance-admin'], true),
            403,
            'You do not have permission to view the audit trail.'
        );

        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:50'],
            'date' => ['nullable', 'date'],
            'record_type' => ['nullable', 'string', 'max:100'],
        ]);

        $rows = AuditTrail::with('user')
            ->when($validated['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($validated['date'] ?? null, fn ($q, $date) => $q->whereDate('created_at', $date))
            ->when($validated['record_type'] ?? null, fn ($q, $type) => $q->where('model_type', $type))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(1000)
            ->get()
            ->map(fn (AuditTrail $entry) => $this->row($entry))
            ->values();

        return response()->json($rows);
    }

    private function row(AuditTrail $entry): array
    {
        $new = $entry->new_values ?? [];
        $old = $entry->old_values ?? [];

        return [
            'id' => $entry->id,
            'user' => $entry->user?->name ?? 'System',
            'action' => $entry->action,
            'record' => $this->recordLabel($entry, $new),
            'recordType' => $entry->model_type,
            'change' => $this->changeSummary($entry->action, $old, $new),
            'ip' => $entry->ip_address ?: '—',
            'date' => $entry->created_at?->toDateString(),
            'ts' => $entry->created_at?->format('M j, Y g:i A'),
        ];
    }

    private function recordLabel(AuditTrail $entry, array $new): string
    {
        $label = $new['title']
            ?? $new['name']
            ?? $new['description']
            ?? $new['reference_no']
            ?? null;

        return $entry->model_type . ' #' . $entry->model_id
            . ($label ? ' — ' . $label : '');
    }

    private function changeSummary(string $action, array $old, array $new): string
    {
        if ($action === 'created') {
            return 'Record created';
        }

        if ($action === 'requested') {
            return $new['title'] ?? 'Request submitted';
        }

        if ($action === 'budget_changed') {
            $reason = $new['reason'] ?? 'Budget allocation changed';

            return isset($old['total_approved_budget'], $new['total_approved_budget'])
                ? $reason . ' (' . $old['total_approved_budget'] . ' → ' . $new['total_approved_budget'] . ')'
                : $reason;
        }

        $changes = [];
        foreach ($new as $key => $value) {
            if (in_array($key, ['updated_at', 'created_at', 'password', 'remember_token'], true)) {
                continue;
            }
            if (! array_key_exists($key, $old) || $old[$key] !== $value) {
                $changes[] = $key . ': ' . $this->stringify($value);
            }
        }

        return $changes ? implode('; ', array_slice($changes, 0, 4)) : ucfirst($action) . ' recorded';
    }

    private function stringify(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) ($value ?? '—');
    }
}
