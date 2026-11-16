import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { ENDPOINTS } from '../../api/endpoints';
import { apiWrite } from '../../api/http';
import { useRoleBadges } from '../../layouts/roleBadges';
import { useApiData } from '../../lib/useApiData';
import { useAssignedProject } from '../../lib/useAssignedProject';
import LoadState from '../../components/LoadState';
import ProgramPill from '../../components/program/ProgramPill';
import ProjectSelect from '../../components/leader/ProjectSelect';
import BudgetRequestDialog from '../../components/leader/BudgetRequestDialog';
import { peso } from '../../components/money';
import { formatDate } from '../../lib/datetime';

const STATUS = { pending: 'Pending', approved: 'Approved', rejected: 'Rejected' };
const NEW_TYPES = ['fund_release', 'realignment', 'liquidation'];

/**
 * My requests (Project Leader): budget requests sent to the Program Leader,
 * with the decision. ?new=fund_release&tranche=ID opens the dialog
 * pre-filled; ?request=ID highlights one row (from a notification).
 */
export default function MyRequests() {
  const picker = useAssignedProject({ role: 'project-leader' });
  const [searchParams, setSearchParams] = useSearchParams();
  const url = picker.projectId ? ENDPOINTS.budgetRequests(picker.projectId) : null;
  const list = useApiData(url);
  const [feedback, setFeedback] = useState(null);
  const rows = Array.isArray(list.data?.budget_requests) ? list.data.budget_requests : [];
  const { setBadges } = useRoleBadges();
  const listShown = list.status === 'ready';
  const projectId = picker.projectId;

  // Once the list is shown, its decisions count as seen (clears the "My requests" badge).
  useEffect(() => {
    if (!projectId || !listShown) return;
    apiWrite('post', ENDPOINTS.budgetRequestsSeen(projectId))
      .then((counts) => setBadges({ requests: Number(counts?.requests) || 0 }))
      .catch(() => {}); // the badge is a convenience
  }, [projectId, listShown, setBadges]);

  const rawNew = searchParams.get('new');
  const dialog = NEW_TYPES.includes(rawNew) ? { type: rawNew, trancheId: searchParams.get('tranche') } : null;
  const highlight = searchParams.get('request');

  function setParams(changes) {
    const next = new URLSearchParams(searchParams);
    Object.entries(changes).forEach(([key, value]) => (value ? next.set(key, value) : next.delete(key)));
    setSearchParams(next, { replace: true });
  }

  return (
    <div className="ld-page">
      <div className="pl-head">
        <div>
          <div className="program-eyebrow">Review · Project Leader</div>
          <h1 className="program-h1">My requests</h1>
          <p className="program-sub">
            {picker.project ? `${picker.project.title} · ${picker.project.code} · ` : ''}
            Fund releases, realignments and liquidations sent to the Program Leader
          </p>
        </div>
        <div className="pl-head-actions">
          <ProjectSelect picker={picker} id="requests-project" />
          {picker.projectId && (
            <button type="button" className="program-btn primary" onClick={() => { setFeedback(null); setParams({ new: 'fund_release', tranche: '' }); }}>
              Submit budget request
            </button>
          )}
        </div>
      </div>

      {feedback && <div className={`program-banner ${feedback.tone}`} role="status" aria-live="polite">{feedback.text}</div>}

      <LoadState state={picker.state} emptyText="You do not lead a project yet." isEmpty={picker.state.status === 'ready' && !picker.project}>
        <LoadState state={list} loadingText="Loading requests…" emptyText="No budget requests yet." isEmpty={rows.length === 0}>
          <section className="program-card">
            <div className="program-scroll">
              <table className="program-table ld-table">
                <thead>
                  <tr>
                    <th scope="col">Request</th>
                    <th scope="col">Status</th>
                    <th scope="col" className="num">Amount</th>
                    <th scope="col">Decided by</th>
                    <th scope="col">Decision date</th>
                    <th scope="col">Remark</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.id} className={String(row.id) === highlight ? 'ld-highlight' : ''}>
                      <td>
                        <div className="project-name">{row.title}</div>
                        <div className="project-code">
                          <ProgramPill value={row.type_label} /> · sent {formatDate(row.created_at)}
                          {row.documents_count > 0 ? ` · ${row.documents_count} document${row.documents_count === 1 ? '' : 's'}` : ''}
                        </div>
                      </td>
                      <td><ProgramPill value={STATUS[row.status] ?? row.status} /></td>
                      <td className="num">{row.amount != null ? peso(row.amount) : '—'}</td>
                      <td>{row.status === 'pending' ? '—' : row.reviewed_by ?? '—'}</td>
                      <td>{row.status === 'pending' ? '—' : formatDate(row.reviewed_at)}</td>
                      <td className="ld-remark">{row.review_notes ? `“${row.review_notes}”` : <span className="muted">No remark</span>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        </LoadState>
      </LoadState>

      {dialog && picker.projectId && (
        <BudgetRequestDialog
          key={`${dialog.type}-${dialog.trancheId ?? ''}`}
          projectId={picker.projectId}
          initial={dialog}
          requests={rows}
          onClose={() => setParams({ new: '', tranche: '' })}
          onSaved={(saved) => {
            setFeedback({ tone: 'ok', text: `Sent “${saved?.title ?? 'request'}” to the Program Leader.` });
            setParams({ new: '', tranche: '', request: saved?.id ? String(saved.id) : '' });
            list.retry();
          }}
        />
      )}
    </div>
  );
}
