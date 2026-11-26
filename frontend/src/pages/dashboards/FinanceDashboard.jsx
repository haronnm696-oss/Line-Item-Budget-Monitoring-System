import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ENDPOINTS } from '../../api/endpoints';
import { useApiData } from '../../lib/useApiData';
import { useAssignedProject } from '../../lib/useAssignedProject';
import { notAssignedText } from '../../lib/myProjects';
import { useUrlQuery } from '../../lib/useUrlQuery';
import { parseOpen } from '../../lib/assistantStatus';
import { formatDate } from '../../lib/datetime';
import LoadState from '../../components/LoadState';
import ProjectLine from '../../components/ProjectLine';
import FinanceRecordPanel from '../../components/finance/FinanceRecordPanel';
import LateReceipts from '../../components/finance/LateReceipts';
import { useVerifyActions } from '../../components/finance/useVerifyActions';
import { formatPct, peso } from '../../components/money';
import Tip, { SPENT_RULE } from '../../components/Tip';

/**
 * Finance/Admin Overview: what waits for verification, what was verified
 * this month, missing receipts and Total spent. "To verify" and the sidebar
 * badge use the same server query (finance-overview / finance-badges).
 * Finance/Admin verifies against the documents; it does not record
 * expenses (the Research Assistant and the Project Leader do).
 *
 * KPI figures are shown as the exact server values (ProjectFinancialService),
 * never a counting animation: an animation paused mid-way (background tab,
 * printing) once showed ₱365.00 for a ₱500.00 Total spent.
 */
export default function FinanceDashboard() {
  const picker = useAssignedProject({ role: 'finance-admin' });
  const overview = useApiData(picker.projectId ? ENDPOINTS.financeOverview(picker.projectId) : null);
  const { get, update } = useUrlQuery();
  const open = parseOpen(get('open'));
  const [refreshKey, setRefreshKey] = useState(0);
  const reload = () => { overview.retry(); setRefreshKey((value) => value + 1); };
  const actions = useVerifyActions({ projectId: picker.projectId, onChanged: reload });

  const data = overview.data;
  const kpis = data?.kpis;
  const q = picker.projectId ? `?project=${picker.projectId}` : '';
  const othersOnly = kpis ? kpis.to_verify - kpis.to_verify_actionable : 0;

  return (
    <div className="ld-page">
      <div className="pl-head">
        <div>
          <h1 className="program-h1">Verification</h1>
          <ProjectLine picker={picker} project={data?.project ?? picker.project} />
        </div>
      </div>

      {actions.feedback && (
        <div className={`program-banner ${actions.feedback.tone}`} role="status" aria-live="polite">{actions.feedback.text}</div>
      )}

      <LoadState state={picker.state} emptyText={notAssignedText(picker.role)} isEmpty={picker.state.status === 'ready' && !picker.project}>
        <LoadState state={overview} loadingText="Loading verification data…">
          {data && (
            <>
              <div className="pl-kpi-grid fin-kpi-grid">
                <Kpi hero to={`/verification-queue${q}`} label="To verify" value={kpis.to_verify}
                  sub={[
                    kpis.to_verify > 0 ? 'Approved by the Project Leader' : null,
                    othersOnly > 0 ? `${othersOnly} need another verifier` : null,
                  ].filter(Boolean).join(' · ')} />
                <Kpi to={`/verification-queue${q}${q ? '&' : '?'}tab=verified`} label="Verified this month" value={kpis.verified_this_month} format={peso}
                  sub={`${plural(kpis.verified_this_month_expenses, 'expense')} · ${kpis.verified_this_month_payroll} payroll`} />
                <Kpi alert={kpis.missing_receipts > 0} to={`/transaction-history${q}${q ? '&' : '?'}receipt=missing`} label="Missing receipts" value={kpis.missing_receipts}
                  sub={kpis.verified_expenses > 0
                    ? `${formatPct(kpis.receipt_compliance_pct)} compliant`
                    : 'No verified expenses yet'} />
                <Kpi to={`/transaction-history${q}`} label="Total spent" tip={SPENT_RULE} value={kpis.total_spent} format={peso}
                  sub={`${formatPct(kpis.utilization_pct)} of ${peso(kpis.total_budget)}`} />
              </div>

              <section className="program-card">
                <div className="program-card-head">
                  <div>
                    <div className="program-card-title">Verification queue</div>
                  </div>
                  <Link className="program-card-link" to={`/verification-queue${q}`}>View all</Link>
                </div>
                {data.queue.length === 0 ? (
                  <p className="ld-empty">Nothing is waiting for verification.</p>
                ) : (
                  <ul className="ra-recent" aria-label="Waiting for verification">
                    {data.queue.map((item) => (
                      <Row key={item.key} item={item} onOpen={() => update({ open: item.key })}
                        meta={[item.line_item || item.details, item.submitted_by?.name ? `${item.type === 'payroll' ? 'prepared' : 'encoded'} by ${item.submitted_by.name}` : null]}
                        date={item.date ?? item.approved_at ?? item.submitted_at}
                        note={(
                          <>
                            {item.type === 'expense' && !(item.receipts_count > 0) && <div className="fin-no-receipt">⚠ No receipt</div>}
                            {item.blocked_text && <div className="ra-next fin-blocked">⊘ {item.blocked_text}</div>}
                            {item.can_verify && (
                              <div className="fin-row-actions ra-row-resubmit">
                                <button type="button" className="program-btn sm primary" onClick={() => update({ open: item.key })}>Review</button>
                              </div>
                            )}
                          </>
                        )} />
                    ))}
                  </ul>
                )}
                {kpis.to_verify > data.queue.length && (
                  <Link className="ld-more" to={`/verification-queue${q}`}>{kpis.to_verify - data.queue.length} more waiting</Link>
                )}
                <LateReceipts items={data.late_receipts} onOpen={(row) => update({ open: row.key })} />
              </section>

              {data.returned_to_you?.length > 0 && (
                <section className="program-card">
                  <div className="program-card-head">
                    <div>
                      <div className="program-card-title">Returned to you</div>
                    </div>
                  </div>
                  <ul className="ra-recent" aria-label="Returned to you">
                    {data.returned_to_you.map((item) => (
                      <Row key={item.key} item={item}
                        meta={[`Ref. ${item.reference}`, item.line_item]}
                        date={item.date ?? item.submitted_at}
                        note={(
                          <>
                            {item.return_reason && <div className="ra-next is-returned">↩ Returned: {item.return_reason}</div>}
                            {item.can_edit && (
                              <div className="fin-row-actions ra-row-resubmit">
                                <button type="button" className="program-btn sm primary" disabled={actions.busyKey === item.key} onClick={() => actions.resubmit(item)}>
                                  {actions.busyKey === item.key ? 'Opening…' : (item.workflow_status === 'returned' ? 'Edit and resubmit' : 'Edit and submit')}
                                </button>
                              </div>
                            )}
                          </>
                        )} />
                    ))}
                  </ul>
                </section>
              )}

              <section className="program-card">
                <div className="program-card-head">
                  <div>
                    <div className="program-card-title">Recently verified</div>
                  </div>
                  <Link className="program-card-link" to={`/transaction-history${q}`}>View transaction history</Link>
                </div>
                {data.recently_verified.length === 0 ? (
                  <p className="ld-empty">You have not verified anything on this project yet.</p>
                ) : (
                  <ul className="ra-recent" aria-label="Recently verified">
                    {data.recently_verified.map((item) => (
                      <Row key={item.key} item={item} onOpen={() => update({ open: item.key })}
                        meta={[`Ref. ${item.reference}`, item.category]}
                        date={item.date ?? item.verified_at} />
                    ))}
                  </ul>
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
    </div>
  );
}

function plural(n, one, many = `${one}s`) {
  return `${n} ${n === 1 ? one : many}`;
}

/**
 * A KPI card that opens its page. With `tip`, the label explains itself
 * (Tip); the label then sits outside the link (a link cannot hold a
 * button) and the figure below it opens the page.
 */
function Kpi({ label, value, sub, to, format, tip = null, alert = false, hero = false }) {
  const figure = (
    <>
      <div className="pl-kpi-value">{format ? format(value) : Number(value || 0).toLocaleString('en-PH')}</div>
      {sub && <div className="pl-kpi-sub">{sub}</div>}
    </>
  );
  if (tip) {
    return (
      <div className={`program-card pl-kpi ra-kpi ra-kpi-split${alert ? ' ra-kpi-alert' : ''}`}>
        <div className="pl-kpi-head"><span className="pl-kpi-label"><Tip text={tip}>{label}</Tip></span></div>
        <Link to={to} className="ra-kpi-link">{figure}</Link>
      </div>
    );
  }
  return (
    <Link to={to} className={`program-card pl-kpi ra-kpi${hero ? ' pl-kpi-hero' : ''}${alert ? ' ra-kpi-alert' : ''}`}>
      <div className="pl-kpi-head"><span className="pl-kpi-label">{label}</span></div>
      {figure}
    </Link>
  );
}

/**
 * One list row in the RA Overview style: title (opens the review panel
 * when `onOpen` is given), a meta line, date and amount. No status pill:
 * each list already says what its rows are.
 */
function Row({ item, meta, date, onOpen = null, note = null }) {
  const title = item.title || item.reference;
  return (
    <li className="ra-recent-row fin-recent-row">
      <div className="ra-recent-main">
        {onOpen
          ? <button type="button" className="ld-link ra-recent-title" onClick={onOpen}>{title}</button>
          : <div className="ra-recent-title">{title}</div>}
        <div className="ra-recent-meta">{meta.filter(Boolean).join(' · ')}</div>
        {note}
      </div>
      <span className="ra-recent-date">{date ? formatDate(date) : ''}</span>
      <span className="ra-recent-amount">{item.amount != null ? peso(item.amount) : ''}</span>
    </li>
  );
}
