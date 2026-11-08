<?php

namespace App\Http\Controllers;

use App\Models\AuditTrail;
use App\Models\Project;
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
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'flagged' => ['nullable', 'boolean'],
        ]);

        $query = AuditTrail::with('user');

        if ($validated['action'] ?? null) {
            $query->where('action', $validated['action']);
        }
        if ($validated['date'] ?? null) {
            $query->whereDate('created_at', $validated['date']);
        }
        if ($validated['from'] ?? null && $validated['to'] ?? null) {
            $query->whereBetween('created_at', [$validated['from'] . ' 00:00:00', $validated['to'] . ' 23:59:59']);
        }
        if ($validated['record_type'] ?? null) {
            $query->where('model_type', $validated['record_type']);
        }
        if (! empty($validated['flagged'])) {
            $query->where(function ($query) {
                $query->whereIn('action', ['rejected', 'budget_changed'])
                    ->orWhereNotNull('reason');
            });
        }

        $rows = $query
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
            'record' => $this->recordLabel($entry, $new, $old),
            'recordType' => $entry->model_type,
            'change' => $this->changeSummary($entry->action, $old, $new),
            'ip' => $entry->ip_address ?: '—',
            'date' => $entry->created_at?->toDateString(),
            'ts' => $entry->created_at?->format('M j, Y g:i A'),
            'project' => $this->projectLabel($entry, $old, $new),
        ];
    }

    private function recordLabel(AuditTrail $entry, array $new, array $old): string
    {
        $type = $entry->model_type;
        $label = $new['title']
            ?? $new['name']
            ?? $new['line_item_name']
            ?? $new['description']
            ?? $new['reference_no']
            ?? null;

        if ($type === 'LibCategory') {
            $label = 'Line item: ' . ($new['line_item_name'] ?? $old['line_item_name'] ?? 'Uncategorized');
        } elseif ($type === 'ApprovalRequest') {
            $label = 'Approval: ' . ($new['title'] ?? 'Request');
        } elseif ($type === 'Expense') {
            $label = 'Expense: ' . ($new['description'] ?? 'Expense');
        } elseif ($type === 'ProjectTranche') {
            $label = 'Tranche: ' . ($new['name'] ?? 'Tranche');
        } elseif ($type === 'User') {
            $label = $new['name'] ?? $old['name'] ?? 'User';
        } elseif ($type === 'Project') {
            $label = $new['title'] ?? $old['title'] ?? 'Project';
        }

        return $type . ' #' . $entry->model_id
            . ($label ? ' — ' . $label : '');
    }

    private function projectLabel(AuditTrail $entry, array $old, array $new): ?string
    {
        $projectId = $new['project_id'] ?? $old['project_id'] ?? null;

        if ($projectId === null) {
            return null;
        }

        return Project::find($projectId)?->title ?? 'Project #' . $projectId;
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
