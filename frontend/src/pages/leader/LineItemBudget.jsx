import { Fragment } from 'react';
import { ENDPOINTS } from '../../api/endpoints';
import { useApiData } from '../../lib/useApiData';
import { useAssignedProject } from '../../lib/useAssignedProject';
import { notAssignedText } from '../../lib/myProjects';
import LoadState from '../../components/LoadState';
import ProgramPill from '../../components/program/ProgramPill';
import ProjectSelect from '../../components/leader/ProjectSelect';
import { formatPct, peso } from '../../components/money';

const GROUPS = [
  ['PS', 'Personal Services (PS)'],
  ['MOOE', 'Maintenance and Other Operating Expenses (MOOE)'],
];

function sum(rows, key) {
  return rows.reduce((total, row) => total + (Number(row[key]) || 0), 0);
}

function usedPct(spent, allocated) {
  return allocated > 0 ? (spent / allocated) * 100 : null;
}

function Cells({ row }) {
  const pct = usedPct(row.spent, row.allocated);
  return (
    <>
      <td className="num">{peso(row.allocated)}</td>
      <td className="num">{peso(row.spent)}</td>
      <td className="num">{peso(row.pending)}</td>
      <td className={`num${row.available < 0 ? ' ld-neg' : ''}`}>{peso(row.available)}</td>
      <td className="num">{pct == null ? '—' : formatPct(pct)}</td>
    </>
  );
}

/**
 * Line-item budget: allocated, spent (verified), pending and available per
 * line item, from the balancing query (ProjectFinancialService::balancing).
 * Grouped by PS and MOOE with subtotals and the total.
 */
export default function LineItemBudget() {
  const picker = useAssignedProject();
  const url = picker.projectId ? ENDPOINTS.balancing(picker.projectId) : null;
  const balancing = useApiData(url);
  const rows = Array.isArray(balancing.data?.line_items) ? balancing.data.line_items : [];
  const totals = balancing.data?.totals;
  const other = rows.filter((row) => !GROUPS.some(([type]) => type === row.type));
  const groups = [...GROUPS, ...(other.length ? [['other', 'Other']] : [])];

  return (
    <div className="ld-page">
      <div className="pl-head">
        <div>
          <div className="program-eyebrow">Project</div>
          <h1 className="program-h1">Line-item budget</h1>
          <p className="program-sub">
            {picker.project ? `${picker.project.title} · ${picker.project.code} · ` : ''}
            Available = allocated − spent − pending. Payroll is not included.
          </p>
        </div>
        <div className="pl-head-actions">
          <ProjectSelect picker={picker} id="budget-project" />
        </div>
      </div>

      <LoadState state={picker.state} emptyText={notAssignedText(picker.role)} isEmpty={picker.state.status === 'ready' && !picker.project}>
        <LoadState state={balancing} loadingText="Loading line items…" emptyText="This project has no line items yet." isEmpty={rows.length === 0}>
          <section className="program-card">
            <div className="program-scroll">
              <table className="program-table ld-table ld-budget-table">
                <thead>
                  <tr>
                    <th scope="col">Line item</th>
                    <th scope="col" className="num">Allocated</th>
                    <th scope="col" className="num">Spent</th>
                    <th scope="col" className="num">Pending</th>
                    <th scope="col" className="num">Available</th>
                    <th scope="col" className="num">% used</th>
                  </tr>
                </thead>
                <tbody>
                  {groups.map(([type, label]) => {
                    const items = type === 'other' ? other : rows.filter((row) => row.type === type);
                    if (items.length === 0) return null;
                    const subtotal = {
                      allocated: sum(items, 'allocated'),
                      spent: sum(items, 'spent'),
                      pending: sum(items, 'pending'),
                      available: sum(items, 'available'),
                    };
                    return (
                      <Fragment key={type}>
                        <tr className="ld-group-row"><th scope="rowgroup" colSpan={6}>{label}</th></tr>
                        {items.map((row) => (
                          <tr key={row.id}>
                            <td>
                              <div className="project-name">{row.name}</div>
                              <div className="project-code">
                                {row.code ? `${row.code} · ` : ''}
                                {row.pending > 0 ? `Pending: ${peso(row.pending_expenses)} expenses, ${peso(row.pending_purchase_requests)} purchase requests` : 'Nothing pending'}
                              </div>
                              {row.available < 0 && <ProgramPill value="Over budget" />}
                            </td>
                            <Cells row={row} />
                          </tr>
                        ))}
                        <tr className="ld-subtotal">
                          <th scope="row">Subtotal {type === 'other' ? '' : type}</th>
                          <Cells row={subtotal} />
                        </tr>
                      </Fragment>
                    );
                  })}
                </tbody>
                {totals && (
                  <tfoot>
                    <tr className="ld-total">
                      <th scope="row">Total</th>
                      <Cells row={totals} />
                    </tr>
                  </tfoot>
                )}
              </table>
            </div>
          </section>
        </LoadState>
      </LoadState>
    </div>
  );
}
