import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { decideApproval, fetchApprovalDetail } from '../../api/program';
import DocumentList from '../leader/DocumentList';
import ProgramPill from './ProgramPill';
import { peso } from '../money';
import { formatDate, formatDateTime } from '../../lib/datetime';
import { reviewPanelWarning } from '../../lib/releasePlan';

const HISTORY_ICON = { requested: '•', approved: '✓', returned: '↩', resubmitted: '↻', withdrawn: '⊘' };
const TRANCHE_STATUS = { released: 'Released', due: 'Due', pending: 'Not yet due', overdue: 'Overdue' };

function messageFor(error, fallback) {
  return error?.response?.data?.errors?.remarks?.[0] || error?.response?.data?.message || fallback;
}

/** "Release 2": the API gives each release its stored number (fields keep "tranche"). */
function releaseName(tranche) {
  return tranche.name;
}

function FundRelease({ details }) {
  const { tranche, released, budget } = details;
  return (
    <>
      <dl className="ld-fields">
        <div><dt>Release</dt><dd>{tranche ? releaseName(tranche) : '—'}</dd></div>
        <div><dt>Release amount</dt><dd>{tranche ? peso(tranche.amount) : '—'}</dd></div>
        <div><dt>Planned date</dt><dd>{tranche?.planned_release_date ? formatDate(tranche.planned_release_date) : '—'}</dd></div>
        <div><dt>Release status</dt><dd>{tranche ? TRANCHE_STATUS[tranche.status] ?? tranche.status : '—'}</dd></div>
        <div><dt>Already released</dt><dd>{peso(released.total)}{released.tranches.length ? ` · ${released.tranches.length} release${released.tranches.length === 1 ? '' : 's'}` : ''}</dd></div>
        <div><dt>Project budget</dt><dd>{peso(budget.budget)}</dd></div>
        <div><dt>Spent (verified)</dt><dd>{peso(budget.spent)}</dd></div>
        <div><dt>Remaining budget</dt><dd><b>{peso(budget.remaining)}</b></dd></div>
      </dl>
      {released.tranches.length > 0 && (
        <ul className="pa-history">
          {released.tranches.map((row) => (
            <li key={row.id}>{releaseName(row)}: {peso(row.amount)}{row.released_at ? ` · released ${formatDate(row.released_at)}` : ''}</li>
          ))}
        </ul>
      )}
    </>
  );
}

const FIGURES = [['allocated', 'Allocated'], ['spent', 'Spent'], ['pending', 'Pending'], ['available', 'Available']];

function LineItemMove({ side, label }) {
  if (!side) return <p className="ld-muted">{label}: this line item no longer exists.</p>;
  return (
    <div className="ra-table-wrap">
      <table className="program-table ld-table">
        <caption className="pa-caption">{label}: {side.code ? `${side.code} ` : ''}{side.name}{side.type ? ` (${side.type})` : ''}</caption>
        <thead>
          <tr><th scope="col">Figure</th><th scope="col" className="num">Before</th><th scope="col" className="num">After</th></tr>
        </thead>
        <tbody>
          {FIGURES.map(([key, text]) => (
            <tr key={key}>
              <th scope="row">{text}</th>
              <td className="num">{peso(side.before[key])}</td>
              <td className="num">{side.before[key] === side.after[key] ? peso(side.after[key]) : <b>{peso(side.after[key])}</b>}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function Realignment({ details }) {
  return (
    <>
      <p className="ld-muted">
        Moves {peso(details.amount)}. {details.applied ? 'Approved: the move is already in the allocations.' : 'Nothing moves until it is approved.'}
      </p>
      <LineItemMove side={details.from} label="From" />
      <LineItemMove side={details.to} label="To" />
    </>
  );
}

function Liquidation({ details }) {
  const { tranche, period, expenses } = details;
  return (
    <>
      <dl className="ld-fields">
        <div><dt>Release liquidated</dt><dd>{tranche ? releaseName(tranche) : 'No release linked'}</dd></div>
        <div><dt>Released amount</dt><dd>{tranche?.status === 'released' ? `${peso(details.released_amount)}${tranche.released_at ? ` · ${formatDate(tranche.released_at)}` : ''}` : 'Not released'}</dd></div>
      </dl>
      {period && (
        <p className="ld-muted">
          Spent (verified expenses and verified payroll) dated {formatDate(period.from)} to {period.to ? formatDate(period.to) : 'today'}
          {period.starts === 'project_start'
            ? ' (first release: from the project start to the next release).'
            : ' (from this release to the next one).'}
        </p>
      )}
      {expenses.length === 0 ? (
        <p className="ld-muted">{period ? 'No verified expenses or payroll in this period.' : 'Spending is shown once the funds for this release are released.'}</p>
      ) : (
        <div className="ra-table-wrap">
          <table className="program-table ld-table">
            <caption className="pa-sr-only">Verified expenses and payroll charged against the release</caption>
            <thead>
              <tr>
                <th scope="col">Date</th>
                <th scope="col">Reference</th>
                <th scope="col">Payee / line item</th>
                <th scope="col" className="num">Amount</th>
              </tr>
            </thead>
            <tbody>
              {expenses.map((row) => (
                <tr key={`${row.kind ?? 'expense'}-${row.id}`}>
                  <td>{formatDate(row.date)}</td>
                  <td>{row.reference_no ?? '—'}</td>
                  <td>{row.payee ?? row.description ?? '—'}{row.line_item ? <div className="project-code">{row.line_item}</div> : null}</td>
                  <td className="num">{peso(row.amount)}</td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr><th scope="row" colSpan={3}>Total ({expenses.length})</th><td className="num"><b>{peso(details.expenses_total)}</b></td></tr>
            </tfoot>
          </table>
        </div>
      )}
    </>
  );
}

function Payroll({ details }) {
  return (
    <dl className="ld-fields">
      <div><dt>Pay period</dt><dd>{details.month}</dd></div>
      <div><dt>Step sent to you</dt><dd>{details.step}</dd></div>
      <div><dt>People paid</dt><dd>{details.people}</dd></div>
      <div><dt>Gross pay</dt><dd>{peso(details.gross_total)}</dd></div>
      <div><dt>Net pay</dt><dd><b>{peso(details.net_total)}</b></dd></div>
    </dl>
  );
}

/** An expense no Finance/Admin of the project may verify: the Program Leader verifies it. */
function ExpenseVerify({ details }) {
  return (
    <dl className="ld-fields">
      <div><dt>Reference</dt><dd>{details.reference}</dd></div>
      <div><dt>Description</dt><dd>{details.description}{details.payee ? ` · ${details.payee}` : ''}</dd></div>
      <div><dt>Date</dt><dd>{formatDate(details.expense_date)}</dd></div>
      <div><dt>Line item</dt><dd>{details.line_item ?? '—'}{details.category ? ` (${details.category})` : ''}</dd></div>
      <div><dt>Amount</dt><dd><b>{peso(details.amount)}</b></dd></div>
      <div><dt>Recorded by</dt><dd>{details.recorded_by ?? '—'}</dd></div>
      <div><dt>Approved by</dt><dd>{details.approved_by ?? 'No Project Leader step (recorded by the Project Leader)'}</dd></div>
      <div><dt>Receipt</dt><dd>{details.receipts_count > 0 ? <>✓ {details.receipts_count} attached</> : <>! No receipt</>}</dd></div>
      <div><dt>Step sent to you</dt><dd>{details.step}: approving counts it in Total spent; returning sends it back to the recorder.</dd></div>
    </dl>
  );
}

const DETAILS = {
  expense: ['Expense to verify', ExpenseVerify],
  fund_release: ['Fund release', FundRelease],
  realignment: ['Line items', Realignment],
  liquidation: ['Liquidation', Liquidation],
  payroll: ['Payroll batch', Payroll],
};

/**
 * Review side panel for one approval request (Program Leader). Shows who
 * asked, the status history, the type's figures and the attachments with
 * preview; Approve / Return (with a required reason) live only here, so a
 * request is always opened before it is decided. `onDecided(text)` runs
 * after a decision is saved.
 */
export default function ApprovalPanel({ approvalId, onClose, onDecided }) {
  const [result, setResult] = useState(null); // { id, approval } | { id, error }
  const [returning, setReturning] = useState(false);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState('');

  useEffect(() => {
    if (approvalId == null) return undefined;
    const controller = new AbortController();
    fetchApprovalDetail(approvalId, { signal: controller.signal })
      .then((data) => setResult({ id: approvalId, approval: data.approval }))
      .catch((error) => {
        if (controller.signal.aborted) return;
        const status = error?.response?.status;
        setResult({
          id: approvalId,
          error: status === 404 ? 'This request no longer exists.' : status === 403 ? 'You cannot see this request.' : 'Could not load this request. Please try again.',
        });
      });
    return () => controller.abort();
  }, [approvalId]);

  useEffect(() => {
    if (approvalId == null) return undefined;
    const onKey = (event) => { if (event.key === 'Escape' && !busy) onClose(); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [approvalId, busy, onClose]);

  if (approvalId == null) return null;
  const approval = result?.id === approvalId ? result.approval : null;
  const error = result?.id === approvalId ? result.error : null;
  const [detailTitle, Details] = DETAILS[approval?.details?.kind] ?? [];
  // Beside Approve: an earlier released release has no approved liquidation (a warning, not a block).
  const liquidationWarning = reviewPanelWarning(approval);

  async function decide(decision) {
    if (busy) return;
    if (decision === 'returned' && !reason.trim()) {
      setActionError('A reason is required to return a request.');
      return;
    }
    setBusy(true);
    setActionError('');
    try {
      await decideApproval(approval.id, { decision, remarks: decision === 'returned' ? reason.trim() : '' });
      onDecided(decision === 'approved'
        ? `Approved “${approval.title}”. The decision was written to the audit trail.`
        : `Returned “${approval.title}” to the requester with your reason. They can edit and resubmit it.`);
    } catch (requestError) {
      setActionError(messageFor(requestError, decision === 'approved' ? 'Unable to approve this request.' : 'Unable to return this request.'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="ld-panel-overlay" role="presentation" onClick={() => !busy && onClose()}>
      <aside className="ld-panel" role="dialog" aria-modal="true" aria-labelledby="pa-panel-title" onClick={(event) => event.stopPropagation()}>
        <div className="ld-panel-head">
          <div>
            <div className="ld-panel-eyebrow">{approval ? `${approval.type_label} · ${approval.project?.code ?? ''}` : 'Approval request'}</div>
            <h2 className="ld-panel-title" id="pa-panel-title">{approval?.title ?? (error ? 'Request' : 'Loading…')}</h2>
          </div>
          <button type="button" className="program-icon-btn" onClick={onClose} disabled={busy} aria-label="Close panel">×</button>
        </div>

        {error && <div className="program-banner error" role="alert">{error}</div>}
        {!approval && !error && <p className="ld-muted" aria-busy="true">Loading…</p>}

        {approval && (
          <>
            <div className="ld-panel-status">
              <ProgramPill value={approval.status_label} />
              {approval.amount != null && <b className="ld-amount">{peso(approval.amount)}</b>}
            </div>

            <dl className="ld-fields">
              <div><dt>Type</dt><dd>{approval.type_label}</dd></div>
              <div><dt>Project</dt><dd>{approval.project ? `${approval.project.title} · ${approval.project.code}` : '—'}</dd></div>
              <div><dt>Requested by</dt><dd>{approval.requested_by ? `${approval.requested_by.name}${approval.requested_by.role ? ` (${approval.requested_by.role})` : ''}` : '—'}</dd></div>
              <div><dt>Submitted</dt><dd>{formatDateTime(approval.created_at)}</dd></div>
              {approval.resubmitted_at && <div><dt>Resubmitted</dt><dd>{formatDateTime(approval.resubmitted_at)}</dd></div>}
              {approval.reviewed_at && <div><dt>Decided</dt><dd>{approval.reviewed_by ?? '—'} · {formatDateTime(approval.reviewed_at)}</dd></div>}
            </dl>
            {approval.justification?.trim()
              ? <p className="pa-remark">“{approval.justification.trim()}”</p>
              : <p className="pa-remark is-empty">No justification</p>}

            <h3 className="ld-panel-sub">Status history</h3>
            <ol className="pa-history">
              {approval.history.map((entry, index) => (
                <li key={`${entry.event}-${index}`}>
                  <span aria-hidden="true">{HISTORY_ICON[entry.event] ?? '•'}</span>{' '}
                  <b>{entry.label}</b> {formatDateTime(entry.at)}{entry.by ? ` · ${entry.by}` : ''}
                  {entry.reason && <div className="ld-reason">Reason: “{entry.reason}”</div>}
                </li>
              ))}
            </ol>
            {approval.project?.id != null && (
              <Link className="pa-audit-link" to={`/program-audit?project=${approval.project.id}`}>
                View in audit trail
              </Link>
            )}

            {Details && (
              <>
                <h3 className="ld-panel-sub">{detailTitle}</h3>
                <Details details={approval.details} />
              </>
            )}

            <h3 className="ld-panel-sub">Attachments</h3>
            <DocumentList type="approval" id={approval.id} documents={approval.documents} emptyText="No attachments" />

            {approval.blocked_text && approval.status === 'pending' && (
              <p className="ld-waiting"><span aria-hidden="true">⚠</span> {approval.blocked_text}</p>
            )}

            {approval.can_decide && (
              <div className="ld-panel-actions pa-panel-actions">
                {liquidationWarning && (
                  <p className="ld-waiting pa-return-field" role="status"><span aria-hidden="true">⚠</span> {liquidationWarning}</p>
                )}
                {returning && (
                  <div className="program-field pa-return-field">
                    <label htmlFor="pa-return-reason">Reason for returning (required)</label>
                    <textarea
                      id="pa-return-reason"
                      className="program-textarea"
                      value={reason}
                      onChange={(event) => setReason(event.target.value)}
                      placeholder="Tell the requester what to fix, e.g. add the work plan"
                      autoFocus
                    />
                  </div>
                )}
                {actionError && <p className="program-error pa-return-field" role="alert">{actionError}</p>}
                {returning ? (
                  <>
                    <button type="button" className="program-btn" disabled={busy} onClick={() => { setReturning(false); setActionError(''); }}>Cancel</button>
                    <button type="button" className="program-btn primary" disabled={busy} onClick={() => decide('returned')}>
                      {busy ? 'Returning…' : 'Return to requester'}
                    </button>
                  </>
                ) : (
                  <>
                    <button type="button" className="program-btn" disabled={busy} onClick={() => { setReturning(true); setActionError(''); }}>Return</button>
                    <button type="button" className="program-btn primary" disabled={busy} onClick={() => decide('approved')}>
                      {busy ? 'Saving…' : 'Approve'}
                    </button>
                  </>
                )}
              </div>
            )}
          </>
        )}
      </aside>
    </div>
  );
}
