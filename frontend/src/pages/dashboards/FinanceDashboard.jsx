import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { ENDPOINTS } from '../../api/endpoints';
import { useApiData } from '../../lib/useApiData';
import { useAssignedProject } from '../../lib/useAssignedProject';
import { notAssignedText } from '../../lib/myProjects';
import { useUrlQuery } from '../../lib/useUrlQuery';
import { parseOpen } from '../../lib/assistantStatus';
import { formatDate, formatInManila } from '../../lib/datetime';
import LoadState from '../../components/LoadState';
import AnimatedNumber from '../../components/program/AnimatedNumber';
import ProgramPill from '../../components/program/ProgramPill';
import ProjectSelect from '../../components/leader/ProjectSelect';
import RecordExpenseDialog from '../../components/leader/RecordExpenseDialog';
import FinanceRecordPanel from '../../components/finance/FinanceRecordPanel';
import LateReceipts from '../../components/finance/LateReceipts';
import { useVerifyActions } from '../../components/finance/useVerifyActions';
import { formatPct, peso } from '../../components/money';

function greeting() {
  const hour = Number(formatInManila(new Date(), { hour: 'numeric', hourCycle: 'h23' }, '12'));
  if (hour < 12) return 'Good morning';
  if (hour < 18) return 'Good afternoon';
  return 'Good evening';
}

/**
 * Finance/Admin Overview: what waits for verification, what was verified
 * this month, missing receipts and Total spent. "To verify" and the sidebar
 * badge use the same server query (finance-overview / finance-badges).
 */
export default function FinanceDashboard() {
  const { user } = useAuth();
  const picker = useAssignedProject({ role: 'finance-admin' });
  const overview = useApiData(picker.projectId ? ENDPOINTS.financeOverview(picker.projectId) : null);
  const { get, update } = useUrlQuery();
  const open = parseOpen(get('open'));
  const [refreshKey, setRefreshKey] = useState(0);
  const [recording, setRecording] = useState(false);
  const reload = () => { overview.retry(); setRefreshKey((value) => value + 1); };
  const actions = useVerifyActions({ projectId: picker.projectId, onChanged: reload });

  const data = overview.data;
  const kpis = data?.kpis;
  const firstName = (user?.name ?? 'there').replace(/^(Dr|Engr|Prof)\.?\s+/i, '').split(' ')[0];
  const q = picker.projectId ? `?project=${picker.projectId}` : '';
  const othersOnly = kpis ? kpis.to_verify - kpis.to_verify_actionable : 0;

  return (
    <div className="ld-page">
      <div className="pl-head">
        <div>
          <div className="pl-greeting">{greeting()}, {firstName}</div>
          <h1 className="program-h1">Verification</h1>
          <p className="program-sub">
            {[data?.project.title ?? picker.project?.title, data?.project.code ?? picker.project?.code, 'only verified amounts count as spent'].filter(Boolean).join(' · ')}
          </p>
        </div>
        {picker.projectId && (
          <div className="pl-head-actions">
            <ProjectSelect picker={picker} id="fin-overview-project" />
            <button type="button" className="program-btn primary" onClick={() => { actions.setFeedback(null); setRecording(true); }}>Record expense</button>
          </div>
        )}
      </div>

      {actions.feedback && (
        <div className={`program-banner ${actions.feedback.tone}`} role="status" aria-live="polite">{actions.feedback.text}</div>
      )}

      <LoadState state={picker.state} emptyText={notAssignedText(picker.role)} isEmpty={picker.state.status === 'ready' && !picker.project}>
        <LoadState state={overview} loadingText="Loading verification data…">
          {data && (
            <>
              <div className="pl-kpi-grid">
                <Kpi to={`/verification-queue${q}`} label="To verify" value={kpis.to_verify}
                  sub={`Approved by the Project Leader${othersOnly > 0 ? ` · ${othersOnly} need another verifier` : ''}`} />
                <Kpi to={`/verification-queue${q}${q ? '&' : '?'}tab=verified`} label="Verified this month" value={kpis.verified_this_month} format={peso}
                  sub={`${kpis.month_label} · ${plural(kpis.verified_this_month_expenses, 'expense')} · ${plural(kpis.verified_this_month_payroll, 'payroll batch', 'payroll batches')}`} />
                <Kpi alert={kpis.missing_receipts > 0} to={`/expenses${q}`} label="Missing receipts" value={kpis.missing_receipts}
                  sub={kpis.verified_expenses > 0
                    ? `Receipt compliance ${formatPct(kpis.receipt_compliance_pct)} · ${kpis.verified_with_receipt} of ${kpis.verified_expenses}`
                    : 'No verified expenses yet'} />
                <Kpi to={`/transaction-history${q}`} label="Total spent" value={kpis.total_spent} format={peso}
                  sub={`Verified, ${formatPct(kpis.utilization_pct)} of ${peso(kpis.total_budget)}`} />
              </div>

              <div className="pl-row pl-row-main">
                <section className="program-card">
                  <div className="program-card-head">
                    <div>
                      <div className="program-card-title">Verification queue</div>
                      <div className="program-card-sub">Approved by the Project Leader, oldest first. Verify against the receipt, or reject with a reason.</div>
                    </div>
                    <Link className="program-card-link" to={`/verification-queue${q}`}>View all</Link>
                  </div>
                  {data.queue.length === 0 ? (
                    <p className="ld-empty">Nothing is waiting for verification.</p>
                  ) : (
                    data.queue.map((item) => <QueueCard key={item.key} item={item} onOpen={() => update({ open: item.key })} />)
                  )}
                  {kpis.to_verify > data.queue.length && (
                    <Link className="ld-more" to={`/verification-queue${q}`}>{kpis.to_verify - data.queue.length} more waiting</Link>
                  )}
                  <p className="fin-note">
                    After you verify, the amount is added to Total spent on every dashboard and appears in reports.
                    A rejection returns the record to the Research Assistant with your reason. Payroll is not counted in LIB spent.
                  </p>
                  <LateReceipts items={data.late_receipts} onOpen={(row) => update({ open: row.key })} />
                </section>

                <section className="program-card">
                  <div className="program-card-head">
                    <div>
                      <div className="program-card-title">Missing receipts</div>
                      <div className="program-card-sub">Verified expenses without an invoice or official receipt</div>
                    </div>
                  </div>
                  {data.missing_receipts.length === 0 ? (
                    <p className="ld-empty">✓ Every verified expense has a receipt.</p>
                  ) : (
                    <ul className="ld-mini-list">
                      {data.missing_receipts.map((row) => (
                        <li key={row.id}>
                          <div>
                            <b>{row.reference_no || `EXP-${row.id}`} · {peso(row.amount)}</b>
                            <span>{row.description}</span>
                          </div>
                          <button type="button" className="program-btn sm" disabled={actions.busyKey === `notify-${row.id}`} onClick={() => actions.notifyRa(row)}>
                            {actions.busyKey === `notify-${row.id}` ? 'Sending…' : 'Notify RA'}
                          </button>
                        </li>
                      ))}
                    </ul>
                  )}
                </section>
              </div>

              <section className="program-card">
                <div className="program-card-head">
                  <div>
                    <div className="program-card-title">Recently verified</div>
                    <div className="program-card-sub">Transactions you verified, newest first</div>
                  </div>
                  <Link className="program-card-link" to={`/transaction-history${q}`}>View transaction history</Link>
                </div>
                {data.recently_verified.length === 0 ? (
                  <p className="ld-empty">You have not verified anything on this project yet.</p>
                ) : (
                  <div className="ra-table-wrap">
                    <table className="program-table ld-table ra-table">
                      <caption className="pa-sr-only">Recently verified</caption>
                      <thead>
                        <tr>
                          <th scope="col">Date</th>
                          <th scope="col">Reference</th>
                          <th scope="col">Description</th>
                          <th scope="col">Category</th>
                          <th scope="col" className="num">Amount</th>
                        </tr>
                      </thead>
                      <tbody>
                        {data.recently_verified.map((item) => (
                          <tr key={item.key}>
                            <td data-label="Date">{formatDate(item.date ?? item.verified_at)}</td>
                            <td data-label="Reference">
                              <button type="button" className="ld-link" onClick={() => update({ open: item.key })}>{item.reference}</button>
                            </td>
                            <td data-label="Description">{item.title}</td>
                            <td data-label="Category">{item.category ? <span className="res-cat">{item.category}</span> : '—'}</td>
                            <td data-label="Amount" className="num ld-amount">{peso(item.amount)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </section>
            </>
          )}
        </LoadState>
      </LoadState>

      {picker.projectId && (
        <FinanceRecordPanel projectId={picker.projectId} target={open} refreshKey={refreshKey} actions={actions} onClose={() => update({ open: '' })} />
      )}
      {actions.dialog}
      {recording && picker.projectId && (
        <RecordExpenseDialog
          assistant
          projectId={picker.projectId}
          onClose={() => { setRecording(false); reload(); }}
          onDone={(text) => { setRecording(false); actions.setFeedback({ tone: 'ok', text }); reload(); }}
        />
      )}
    </div>
  );
}

function plural(n, one, many = `${one}s`) {
  return `${n} ${n === 1 ? one : many}`;
}

function Kpi({ label, value, sub, to, format, alert = false }) {
  return (
    <Link to={to} className={`program-card pl-kpi ra-kpi${alert ? ' ra-kpi-alert' : ''}`}>
      <div className="pl-kpi-head"><span className="pl-kpi-label">{label}</span></div>
      <div className="pl-kpi-value"><AnimatedNumber value={value} format={format} /></div>
      <div className="pl-kpi-sub">{sub}</div>
    </Link>
  );
}

/** One record waiting for verification, with Verify / Reject when this user may verify. */
function QueueCard({ item, onOpen }) {
  const receipt = item.type === 'expense'
    ? (item.receipts_count > 0
      ? <ProgramPill value="Receipt attached" tone="healthy" icon="✓" />
      : <ProgramPill value="No receipt" tone="risk" icon="!" />)
    : null;

  return (
    <div className="fin-queue-card">
      <div className="fin-queue-main">
        <button type="button" className="ld-link" onClick={onOpen}>{item.reference}</button>
        <div className="fin-queue-title">{item.title}</div>
        {receipt}
        <div className="ra-details">
          {[item.line_item ? `Line item: ${item.line_item}` : item.details, item.submitted_by?.name ? `encoded by ${item.submitted_by.name}` : null].filter(Boolean).join(' · ')}
        </div>
        <div className="ra-details">
          {item.approved_by ? `Approved by ${item.approved_by.name}${item.approved_at ? ` · ${formatDate(item.approved_at)}` : ''}` : item.approval_note}
        </div>
        {item.blocked_text && <div className="ra-next fin-blocked">⊘ {item.blocked_text}</div>}
      </div>
      <div className="fin-queue-side">
        <b className="ld-amount">{peso(item.amount)}</b>
        {item.can_verify && (
          <div className="fin-row-actions">
            <button type="button" className="program-btn sm primary" onClick={onOpen}>Review</button>
          </div>
        )}
      </div>
    </div>
  );
}
