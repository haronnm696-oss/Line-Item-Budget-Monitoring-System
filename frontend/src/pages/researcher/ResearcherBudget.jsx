import { Fragment } from 'react';
import { ENDPOINTS } from '../../api/endpoints';
import { useApiData } from '../../lib/useApiData';
import { useAssignedProject } from '../../lib/useAssignedProject';
import { notAssignedText } from '../../lib/myProjects';
import LoadState from '../../components/LoadState';
import { formatPct, peso } from '../../components/money';

const GROUPS = [
  ['PS', 'Personal Services (PS)'],
  ['MOOE', 'Maintenance and Other Operating Expenses (MOOE)'],
];

function total(rows) {
  const sum = (key) => Math.round(rows.reduce((value, row) => value + (Number(row[key]) || 0), 0) * 100) / 100;
  return { allocated: sum('allocated'), spent: sum('spent'), remaining: sum('remaining') };
}

function Cells({ allocated, spent, remaining, pct }) {
  return (
    <>
      <td className="num">{peso(allocated)}</td>
      <td className="num">{peso(spent)}</td>
      <td className={`num${remaining < 0 ? ' ld-neg' : ''}`}>{remaining < 0 ? '⚠ ' : ''}{peso(remaining)}</td>
      <td className="num">{pct == null ? '—' : formatPct(pct)}</td>
    </>
  );
}

const pctOf = (spent, allocated) => (allocated > 0 ? (spent / allocated) * 100 : null);

/**
 * Budget (Researcher, read-only): every line item grouped by PS and MOOE
 * with allocated, spent (verified), remaining and % used, from
 * ProjectFinancialService::categoryRows (the same rows as the Program
 * Leader's project page).
 */
export default function ResearcherBudget() {
  const picker = useAssignedProject({ role: 'researcher' });
  const summary = useApiData(picker.projectId ? ENDPOINTS.budgetSummary(picker.projectId) : null);
  const rows = Array.isArray(summary.data?.line_items) ? summary.data.line_items : [];
  const all = total(rows);

  return (
    <div className="ld-page">
      <div className="pl-head">
        <div>
          <div className="program-eyebrow">View · Researcher</div>
          <h1 className="program-h1 res-title">
            Budget <span className="res-readonly"><span aria-hidden="true">👁</span> Read-only access</span>
          </h1>
          <p className="program-sub">
            {picker.project ? `${picker.project.title} · ${picker.project.code} · ` : ''}Spent = verified expenses and verified payroll
          </p>
        </div>
      </div>

      <LoadState state={picker.state} emptyText={notAssignedText(picker.role)} isEmpty={picker.state.status === 'ready' && !picker.project}>
        <LoadState state={summary} loadingText="Loading line items…" emptyText="This project has no line items yet." isEmpty={rows.length === 0}>
          <section className="program-card">
            <div className="ra-table-scroll">
              <table className="program-table ld-table">
                <caption className="pa-sr-only">Line-item budget</caption>
                <thead>
                  <tr>
                    <th scope="col">Line item</th>
                    <th scope="col" className="num">Allocated</th>
                    <th scope="col" className="num">Spent</th>
                    <th scope="col" className="num">Remaining</th>
                    <th scope="col" className="num">% used</th>
                  </tr>
                </thead>
                <tbody>
                  {GROUPS.map(([type, label]) => {
                    const group = rows.filter((row) => row.type === type);
                    if (group.length === 0) return null;
                    const sub = total(group);
                    return (
                      <Fragment key={type}>
                        <tr className="ld-group-row"><th colSpan={5} scope="colgroup">{label}</th></tr>
                        {group.map((row) => (
                          <tr key={row.id}>
                            <td>
                              <div className="project-name">{row.name}</div>
                              {row.code && <div className="project-code">{row.code}</div>}
                            </td>
                            <Cells allocated={row.allocated} spent={row.spent} remaining={row.remaining} pct={row.utilization_pct} />
                          </tr>
                        ))}
                        <tr className="ld-subtotal">
                          <th scope="row">{type} subtotal</th>
                          <Cells {...sub} pct={pctOf(sub.spent, sub.allocated)} />
                        </tr>
                      </Fragment>
                    );
                  })}
                  <tr className="ld-total">
                    <th scope="row">Total</th>
                    <Cells {...all} pct={pctOf(all.spent, all.allocated)} />
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </LoadState>
      </LoadState>
    </div>
  );
}
