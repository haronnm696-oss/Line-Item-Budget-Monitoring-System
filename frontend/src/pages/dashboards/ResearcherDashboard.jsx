import { Link } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { ENDPOINTS } from '../../api/endpoints';
import { useApiData } from '../../lib/useApiData';
import { useAssignedProject } from '../../lib/useAssignedProject';
import { notAssignedText } from '../../lib/myProjects';
import { formatDate, formatInManila } from '../../lib/datetime';
import LoadState from '../../components/LoadState';
import AnimatedNumber from '../../components/program/AnimatedNumber';
import MonthlyExpenseTrend from '../../components/program/MonthlyExpenseTrend';
import PsMooeCard from '../../components/program/PsMooeCard';
import ProjectSelect from '../../components/leader/ProjectSelect';
import { formatPct, peso } from '../../components/money';

const REPORTS = [
  ['utilization', 'Budget utilization', 'Allocated, spent and remaining per line item'],
  ['ledger', 'Expense ledger', 'Every verified expense with its reference number'],
  ['monthly-summary', 'Monthly financial summary', 'Expense trend and category comparison'],
];

function greeting() {
  const hour = Number(formatInManila(new Date(), { hour: 'numeric', hourCycle: 'h23' }, '12'));
  if (hour < 12) return 'Good morning';
  if (hour < 18) return 'Good afternoon';
  return 'Good evening';
}

function clampPct(value) {
  return Math.max(0, Math.min(100, Number(value) || 0));
}

/**
 * Researcher Overview (read-only): the project's budget, spending trend,
 * PS vs MOOE, latest verified transactions and the reports. Every number
 * comes from GET /projects/:id/budget-summary (the same calls as the
 * Project Leader Overview). No control here changes data.
 */
export default function ResearcherDashboard() {
  const { user } = useAuth();
  const picker = useAssignedProject({ role: 'researcher' });
  const summary = useApiData(picker.projectId ? ENDPOINTS.budgetSummary(picker.projectId) : null);
  const data = summary.data;
  const firstName = (user?.name ?? 'there').replace(/^(Dr|Engr|Prof)\.?\s+/i, '').split(' ')[0];
  const q = picker.projectId ? `?project=${picker.projectId}` : '';

  return (
    <div className="ld-page">
      <div className="pl-head">
        <div>
          <div className="pl-greeting res-greeting">
            {greeting()}, {firstName}
            <span className="res-readonly"><span aria-hidden="true">👁</span> Read-only access</span>
          </div>
          <h1 className="program-h1">Budget information</h1>
          <p className="program-sub">
            {[data?.project.title ?? picker.project?.title, data?.project.code ?? picker.project?.code, 'verified data only'].filter(Boolean).join(' · ')}
          </p>
        </div>
        <div className="pl-head-actions"><ProjectSelect picker={picker} id="res-overview-project" /></div>
      </div>

      <LoadState state={picker.state} emptyText={notAssignedText(picker.role)} isEmpty={picker.state.status === 'ready' && !picker.project}>
        <LoadState state={summary} loadingText="Loading the budget…">
          {data && (
            <>
              <div className="pl-kpi-grid">
                <section className="program-card pl-kpi pl-kpi-hero">
                  <div className="pl-kpi-head"><span className="pl-kpi-label">Total budget</span></div>
                  <div className="pl-kpi-value"><AnimatedNumber value={data.kpis.total_budget} format={peso} /></div>
                  <div className="pl-kpi-sub">{data.project.funder ?? 'Funder'} {peso(data.kpis.funder_share)} · MSU–Main Campus {peso(data.kpis.counterpart)}</div>
                  <div className="pl-track pl-track-gold"><span style={{ width: `${clampPct(data.kpis.utilization_pct)}%` }} /></div>
                  <div className="pl-kpi-foot">{formatPct(data.kpis.utilization_pct)} spent</div>
                </section>
                <section className="program-card pl-kpi">
                  <div className="pl-kpi-head"><span className="pl-kpi-label">Total spent</span></div>
                  <div className="pl-kpi-value"><AnimatedNumber value={data.kpis.total_spent} format={peso} /></div>
                  <div className="pl-kpi-sub">{formatPct(data.kpis.utilization_pct)} of the total budget · verified expenses and verified payroll</div>
                  <div className="pl-track"><span style={{ width: `${clampPct(data.kpis.utilization_pct)}%` }} /></div>
                </section>
                <section className="program-card pl-kpi">
                  <div className="pl-kpi-head"><span className="pl-kpi-label">Remaining balance</span></div>
                  <div className="pl-kpi-value"><AnimatedNumber value={data.kpis.remaining} format={peso} /></div>
                  <div className="pl-kpi-sub">{formatPct(data.kpis.total_budget > 0 ? (data.kpis.remaining / data.kpis.total_budget) * 100 : 0)} still available</div>
                </section>
                <section className="program-card pl-kpi">
                  <div className="pl-kpi-head"><span className="pl-kpi-label">Verified transactions</span></div>
                  <div className="pl-kpi-value"><AnimatedNumber value={data.kpis.verified_transactions} /></div>
                  <div className="pl-kpi-sub">{data.kpis.verified_with_receipt} with receipts on file</div>
                </section>
              </div>

              <div className="pl-row pl-row-trend">
                <MonthlyExpenseTrend months={data.monthly_expenses} />
                <PsMooeCard
                  summary={data.category_summary}
                  action={<Link className="program-card-link" to={`/budget${q}`}>View every line item</Link>}
                />
              </div>

              <div className="pl-row pl-row-charts">
                <section className="program-card">
                  <div className="program-card-head">
                    <div>
                      <div className="program-card-title">Transaction summary</div>
                      <div className="program-card-sub">Latest verified transactions</div>
                    </div>
                    <Link className="program-card-link" to={`/transactions${q}`}>View all</Link>
                  </div>
                  {data.transactions.length === 0 ? (
                    <p className="ld-empty">No verified expenses or payroll yet.</p>
                  ) : (
                    <TransactionTable rows={data.transactions} />
                  )}
                </section>

                <section className="program-card">
                  <div className="program-card-head">
                    <div>
                      <div className="program-card-title">Reports</div>
                      <div className="program-card-sub">View and export, for this project</div>
                    </div>
                    <Link className="program-card-link" to={`/reports${q}`}>All reports</Link>
                  </div>
                  <ul className="ld-mini-list">
                    {REPORTS.map(([key, title, desc]) => (
                      <li key={key}>
                        <div>
                          <b>{title}</b>
                          <span>{desc}</span>
                        </div>
                        <div className="res-export">
                          <Link className="program-btn sm" to={`/reports${q}${q ? '&' : '?'}report=${key}&format=pdf`} aria-label={`${title} as PDF`}>PDF</Link>
                          <Link className="program-btn sm" to={`/reports${q}${q ? '&' : '?'}report=${key}&format=csv`} aria-label={`${title} as CSV`}>CSV</Link>
                        </div>
                      </li>
                    ))}
                  </ul>
                </section>
              </div>
            </>
          )}
        </LoadState>
      </LoadState>
    </div>
  );
}

/** Latest verified transactions: date, reference, description, category, amount. */
function TransactionTable({ rows }) {
  return (
    <div className="ra-table-wrap">
      <table className="program-table ld-table ra-table">
        <caption className="pa-sr-only">Latest verified transactions</caption>
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
          {rows.map((row) => (
            <tr key={row.id}>
              <td data-label="Date">{formatDate(row.date)}</td>
              <td data-label="Reference">{row.reference_no || `EXP-${row.id}`}</td>
              <td data-label="Description">{row.description}</td>
              <td data-label="Category">{row.category ? <span className="res-cat">{row.category}</span> : '—'}</td>
              <td data-label="Amount" className="num ld-amount">{peso(row.amount)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
