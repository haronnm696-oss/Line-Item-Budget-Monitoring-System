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
            'project_id' => ['nullable', 'integer'],
        ]);

        $query = AuditTrail::with('user');
        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;

        if ($validated['action'] ?? null) {
            $query->where('action', $validated['action']);
        }
        if ($validated['date'] ?? null) {
            $query->whereDate('created_at', $validated['date']);
        }
        if ($from && $to) {
            $query->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59']);
        } elseif ($from) {
            $query->where('created_at', '>=', $from . ' 00:00:00');
        } elseif ($to) {
            $query->where('created_at', '<=', $to . ' 23:59:59');
        }
        if ($validated['record_type'] ?? null) {
            $query->where('model_type', $validated['record_type']);
        }
        if ($validated['project_id'] ?? null) {
            // The project record itself, plus any record whose logged values
            // carry its project_id (same rule as the project detail activity).
            $projectId = (int) $validated['project_id'];
            $query->where(function ($query) use ($projectId) {
                $query->where(function ($own) use ($projectId) {
                    $own->where('model_type', 'Project')->where('model_id', $projectId);
                })
                    ->orWhere('new_values->project_id', $projectId)
                    ->orWhere('old_values->project_id', $projectId);
            });
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
            'change' => $this->changeSummary($entry->action, $old, $new, $entry->reason),
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

    private function changeSummary(string $action, array $old, array $new, ?string $reason = null): string
    {
        if ($action === 'created') {
            return 'Record created';
        }

        if ($action === 'requested') {
            return $new['title'] ?? 'Request submitted';
        }

        if ($action === 'budget_changed') {
            $summary = $reason ?? $new['reason'] ?? 'Budget allocation changed';

            return isset($old['total_approved_budget'], $new['total_approved_budget'])
                ? $summary . ' (' . $old['total_approved_budget'] . ' → ' . $new['total_approved_budget'] . ')'
                : $summary;
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

        if ($reason) {
            $changes[] = 'reason: ' . $reason;
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
