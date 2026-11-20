import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { ENDPOINTS } from '../../api/endpoints';
import { apiWrite } from '../../api/http';
import { errorText, withdrawBudgetRequest } from '../../api/leader';
import { useRoleBadges } from '../../layouts/roleBadges';
import { useApiData } from '../../lib/useApiData';
import { useAssignedProject } from '../../lib/useAssignedProject';
import { notAssignedText } from '../../lib/myProjects';
import LoadState from '../../components/LoadState';
import ProgramPill from '../../components/program/ProgramPill';
import BudgetRequestDialog from '../../components/leader/BudgetRequestDialog';
import { peso } from '../../components/money';
import { formatDate } from '../../lib/datetime';
import { statusLabel } from '../../lib/budgetRequestForm';

const NEW_TYPES = ['fund_release', 'realignment', 'liquidation'];

/**
 * My requests (Project Leader): budget requests sent to the Program Leader,
 * with the decision. ?new=fund_release&tranche=ID opens the dialog
 * pre-filled; ?request=ID highlights one row (from a notification);
 * ?edit=ID opens "Edit and resubmit" for a returned request. A returned
 * request can also be withdrawn.
 */
export default function MyRequests() {
  const picker = useAssignedProject({ role: 'project-leader' });
  const [searchParams, setSearchParams] = useSearchParams();
  const url = picker.projectId ? ENDPOINTS.budgetRequests(picker.projectId) : null;
  const list = useApiData(url);
  const [feedback, setFeedback] = useState(null);
  const [confirmWithdraw, setConfirmWithdraw] = useState(null); // row id
  const [withdrawing, setWithdrawing] = useState(false);
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
  const editRow = rows.find((row) => String(row.id) === searchParams.get('edit') && row.can_resubmit && row.edit) ?? null;

  function setParams(changes) {
    const next = new URLSearchParams(searchParams);
    Object.entries(changes).forEach(([key, value]) => (value ? next.set(key, value) : next.delete(key)));
    setSearchParams(next, { replace: true });
  }

  async function withdraw(row) {
    if (withdrawing) return;
    setWithdrawing(true);
    setFeedback(null);
    try {
      await withdrawBudgetRequest(row.id);
      setFeedback({ tone: 'ok', text: `Withdrew “${row.title}”. It is closed and no longer waits for a decision.` });
      setConfirmWithdraw(null);
      list.retry();
    } catch (error) {
      setFeedback({ tone: 'error', text: errorText(error, 'Could not withdraw the request. Please try again.') });
    } finally {
      setWithdrawing(false);
    }
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
          {picker.projectId && (
            <button type="button" className="program-btn primary" onClick={() => { setFeedback(null); setParams({ new: 'fund_release', tranche: '', edit: '' }); }}>
              Submit budget request
            </button>
          )}
        </div>
      </div>

      {feedback && <div className={`program-banner ${feedback.tone}`} role="status" aria-live="polite">{feedback.text}</div>}

      <LoadState state={picker.state} emptyText={notAssignedText(picker.role)} isEmpty={picker.state.status === 'ready' && !picker.project}>
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
                    <th scope="col"><span className="pa-sr-only">Actions</span></th>
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
                      <td>
                        <ProgramPill value={statusLabel(row.status)} />
                        {row.status === 'withdrawn' && row.withdrawn_at && <div className="project-code">on {formatDate(row.withdrawn_at)}</div>}
                        {row.status === 'pending' && row.resubmitted_at && <div className="project-code">resubmitted {formatDate(row.resubmitted_at)}</div>}
                      </td>
                      <td className="num">{row.amount != null ? peso(row.amount) : '—'}</td>
                      <td>{row.status === 'pending' ? '—' : row.reviewed_by ?? '—'}</td>
                      <td>{row.status === 'pending' ? '—' : formatDate(row.reviewed_at)}</td>
                      <td className="ld-remark">
                        {row.review_notes
                          ? <>{row.status === 'returned' || row.status === 'withdrawn' ? <b>Reason: </b> : null}“{row.review_notes}”</>
                          : row.status === 'pending' && row.last_return_reason
                            ? <span className="muted">Returned earlier: “{row.last_return_reason}”</span>
                            : <span className="muted">No remark</span>}
                      </td>
                      <td>
                        {row.can_resubmit && confirmWithdraw !== row.id && (
                          <div className="ld-doc-actions">
                            <button type="button" className="program-btn sm primary" onClick={() => { setFeedback(null); setParams({ edit: String(row.id), new: '', tranche: '' }); }}>
                              Edit and resubmit
                            </button>
                            {row.can_withdraw && (
                              <button type="button" className="program-btn sm" onClick={() => setConfirmWithdraw(row.id)}>Withdraw</button>
                            )}
                          </div>
                        )}
                        {confirmWithdraw === row.id && (
                          <div className="ld-doc-actions" role="group" aria-label="Confirm withdrawal">
                            <span>Withdraw this request? It cannot be resubmitted afterwards.</span>
                            <button type="button" className="program-btn sm danger" onClick={() => withdraw(row)} disabled={withdrawing}>
                              {withdrawing ? 'Withdrawing…' : 'Withdraw'}
                            </button>
                            <button type="button" className="program-btn sm" onClick={() => setConfirmWithdraw(null)} disabled={withdrawing}>Keep</button>
                          </div>
                        )}
                      </td>
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

      {editRow && !dialog && picker.projectId && (
        <BudgetRequestDialog
          key={`edit-${editRow.id}`}
          projectId={picker.projectId}
          request={editRow}
          requests={rows}
          onClose={() => setParams({ edit: '' })}
          onSaved={(saved) => {
            setFeedback({ tone: 'ok', text: `Resubmitted “${saved?.title ?? editRow.title}” to the Program Leader.` });
            setParams({ edit: '', request: String(editRow.id) });
            list.retry();
          }}
        />
      )}
    </div>
  );
}
