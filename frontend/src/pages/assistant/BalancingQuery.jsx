import { Fragment } from 'react';
import { ENDPOINTS } from '../../api/endpoints';
import { useApiData } from '../../lib/useApiData';
import { useAssignedProject } from '../../lib/useAssignedProject';
import { useUrlQuery } from '../../lib/useUrlQuery';
import LoadState from '../../components/LoadState';
import ProjectSelect from '../../components/leader/ProjectSelect';
import { peso } from '../../components/money';

const GROUPS = [
  ['PS', 'Personal Services (PS)'],
  ['MOOE', 'Maintenance and Other Operating Expenses (MOOE)'],
];

function sum(rows, key) {
  return Math.round(rows.reduce((total, row) => total + (Number(row[key]) || 0), 0) * 100) / 100;
}

function Money({ value }) {
  const negative = Number(value) < 0;
  return <span className={negative ? 'ld-neg' : ''}>{negative ? '⚠ ' : ''}{peso(value)}</span>;
}

/**
 * Balancing query (Research Assistant): every line item of the project,
 * grouped by PS and MOOE with subtotals and the project total. Figures come
 * from GET /projects/:id/balancing; ?line_item= highlights one row.
 */
export default function BalancingQuery() {
  const picker = useAssignedProject({ role: 'research-assistant' });
  const { get } = useUrlQuery();
  const state = useApiData(picker.projectId ? ENDPOINTS.balancing(picker.projectId) : null);
  const rows = state.data?.line_items ?? [];
  const totals = state.data?.totals;
  const highlight = get('line_item');

  return (
    <div className="ld-page">
      <div className="pl-head">
        <div>
          <div className="program-eyebrow">Track · Research Assistant</div>
          <h1 className="program-h1">Balancing query</h1>
          <p className="program-sub">
            {picker.project ? `${picker.project.title} · ${picker.project.code} · ` : ''}Available = Allocated − Spent − Pending. Payroll is not part of LIB spent.
          </p>
        </div>
        <div className="pl-head-actions"><ProjectSelect picker={picker} id="ra-balancing-project" /></div>
      </div>

      <LoadState state={picker.state} emptyText="You are not assigned to a project as a Research Assistant yet." isEmpty={picker.state.status === 'ready' && !picker.project}>
        <LoadState state={state} loadingText="Loading line items…" emptyText="This project has no line items yet." isEmpty={rows.length === 0}>
          <section className="program-card">
            <div className="ra-table-scroll">
              <table className="program-table ld-table">
                <caption className="pa-sr-only">Balancing per line item</caption>
                <thead>
                  <tr>
                    <th scope="col">Line item</th>
                    <th scope="col" className="num">Allocated</th>
                    <th scope="col" className="num">Spent</th>
                    <th scope="col" className="num">Pending expenses</th>
                    <th scope="col" className="num">Pending requests</th>
                    <th scope="col" className="num">Available</th>
                  </tr>
                </thead>
                <tbody>
                  {GROUPS.map(([type, label]) => {
                    const group = rows.filter((row) => row.type === type);
                    if (group.length === 0) return null;
                    return (
                      <Fragment key={type}>
                        <tr className="ld-group-row"><th colSpan={6} scope="colgroup">{label}</th></tr>
                        {group.map((row) => (
                          <tr key={row.id} className={String(row.id) === highlight ? 'ld-highlight' : ''}>
                            <td>
                              <div className="project-name">{row.name}</div>
                              {row.code && <div className="project-code">{row.code}</div>}
                            </td>
                            <td className="num">{peso(row.allocated)}</td>
                            <td className="num">{peso(row.spent)}</td>
                            <td className="num">{peso(row.pending_expenses)}</td>
                            <td className="num">{peso(row.pending_purchase_requests)}</td>
                            <td className="num ld-amount"><Money value={row.available} /></td>
                          </tr>
                        ))}
                        <tr className="ld-subtotal">
                          <th scope="row">{type} subtotal</th>
                          <td className="num">{peso(sum(group, 'allocated'))}</td>
                          <td className="num">{peso(sum(group, 'spent'))}</td>
                          <td className="num">{peso(sum(group, 'pending_expenses'))}</td>
                          <td className="num">{peso(sum(group, 'pending_purchase_requests'))}</td>
                          <td className="num"><Money value={sum(group, 'available')} /></td>
                        </tr>
                      </Fragment>
                    );
                  })}
                  {totals && (
                    <tr className="ld-total">
                      <th scope="row">Project total</th>
                      <td className="num">{peso(totals.allocated)}</td>
                      <td className="num">{peso(totals.spent)}</td>
                      <td className="num" colSpan={2}>{peso(totals.pending)} pending</td>
                      <td className="num"><Money value={totals.available} /></td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
            <p className="ld-muted">
              Spent = verified expenses. Pending = expenses submitted but not yet verified, plus approved purchase requests not yet fulfilled.
              A linked expense replaces its purchase request, so nothing is counted twice.
            </p>
          </section>
        </LoadState>
      </LoadState>
    </div>
  );
}
