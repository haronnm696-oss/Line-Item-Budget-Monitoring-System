import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ENDPOINTS } from '../../api/endpoints';
import { useApiData } from '../../lib/useApiData';
import { peso } from '../money';
import Tip from '../Tip';

/**
 * Balancing query for one line item (ProjectFinancialService::balancing):
 * Allocated, Spent (verified), Pending and Available. Used on the Overview.
 */
export default function BalancingCheck({ projectId }) {
  const state = useApiData(ENDPOINTS.balancing(projectId));
  const items = state.data?.line_items ?? [];
  const [choice, setChoice] = useState('');
  const row = items.find((item) => String(item.id) === choice) ?? items[0] ?? null;

  return (
    <section className="program-card">
      <div className="program-card-head">
        <div>
          <div className="program-card-title">Balancing query</div>
          <div className="program-card-sub">Check a line item before you encode</div>
        </div>
        <Link className="program-card-link" to={`/balancing-query?project=${projectId}`}>Every line item</Link>
      </div>

      {state.status === 'error' && (
        <div className="program-banner error" role="alert">
          Could not load the line items. <button type="button" className="program-btn sm" onClick={state.retry}>Try again</button>
        </div>
      )}
      {state.status === 'loading' && <p className="ld-muted" aria-busy="true">Loading line items…</p>}
      {state.status === 'ready' && items.length === 0 && <p className="ld-empty">This project has no line items yet.</p>}
      {row && (
        <div className="ra-balance-row">
          <div className="program-field ra-balance-line">
            <label htmlFor="ra-balance-line">Line item</label>
            <select id="ra-balance-line" className="program-select" value={String(row.id)} onChange={(event) => setChoice(event.target.value)}>
              {items.map((item) => <option key={item.id} value={item.id}>{item.name} ({item.type})</option>)}
            </select>
          </div>
          <BalanceFigures
            row={row}
            pendingTip={`Pending = expenses submitted but not yet verified (${peso(row.pending_expenses)}) plus approved purchase requests not yet fulfilled (${peso(row.pending_purchase_requests)}).`}
          />
        </div>
      )}
    </section>
  );
}

export function BalanceFigures({ row, pendingTip = null }) {
  const negative = Number(row.available) < 0;
  return (
    <div className="ra-balance-grid">
      <div className="ra-balance"><span>Allocated</span><b>{peso(row.allocated)}</b></div>
      <div className="ra-balance"><span>Spent</span><b>{peso(row.spent)}</b></div>
      <div className="ra-balance">
        <span>Pending{pendingTip && <> <Tip icon text={pendingTip} label="What Pending includes" /></>}</span>
        <b>{peso(row.pending)}</b>
      </div>
      <div className={`ra-balance ${negative ? 'is-over' : 'is-available'}`}>
        <span>Available</span>
        <b>{negative ? '⚠ ' : ''}{peso(row.available)}</b>
      </div>
    </div>
  );
}
