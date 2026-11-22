import { useState } from 'react';
import { ENDPOINTS } from '../../api/endpoints';
import { useApiData } from '../../lib/useApiData';
import { formatDate } from '../../lib/datetime';
import LoadState from '../LoadState';
import StagePill from '../assistant/StagePill';
import PurchaseRequestItemsTable from '../PurchaseRequestItemsTable';
import { peso } from '../money';

const NUMBERS = [['pr', 'PR'], ['sai', 'SAI'], ['alobs', 'ALOBS']];

/**
 * Purchase requests of one project (Program Leader's project page, read
 * only): purpose, stage, total, PR / SAI / ALOBS numbers and, per request,
 * the item table (each item charged to its own line item).
 */
export default function PurchaseRequestsSection({ projectId }) {
  const list = useApiData(projectId ? ENDPOINTS.purchaseRequests(projectId) : null);
  const rows = (Array.isArray(list.data?.purchase_requests) ? list.data.purchase_requests : [])
    .filter((row) => row.workflow_status !== 'draft');
  const [open, setOpen] = useState(() => new Set());
  const toggle = (id) => setOpen((current) => {
    const next = new Set(current);
    if (next.has(id)) next.delete(id); else next.add(id);
    return next;
  });

  return (
    <section className="program-card">
      <div className="program-card-head">
        <div>
          <div className="program-card-title">Purchase requests</div>
          <div className="program-card-sub">Submitted requests with their items; each item is charged to its own line item. Drafts are not shown.</div>
        </div>
      </div>
      <LoadState state={list} loadingText="Loading purchase requests…" emptyText="No purchase request has been submitted." isEmpty={rows.length === 0}>
        <ul className="pr-item-list">
          {rows.map((row) => {
            const numbers = NUMBERS
              .filter(([key]) => row[`${key}_no`] || row[`${key}_date`])
              .map(([key, label]) => `${label} ${row[`${key}_no`] || '—'}${row[`${key}_date`] ? ` (${formatDate(row[`${key}_date`])})` : ''}`);
            const count = row.items?.length ?? 1;
            return (
              <li key={row.id} className="pr-item">
                <div className="pl-head">
                  <div>
                    <b>PR-{row.id} · {row.purpose ?? row.description}</b>
                    <div className="ld-muted">
                      {count} {count === 1 ? 'item' : 'items'} · {row.line_item}{row.requested_by?.name ? ` · by ${row.requested_by.name}` : ''}
                      {numbers.length > 0 ? ` · ${numbers.join(' · ')}` : ''}
                    </div>
                  </div>
                  <div className="pl-head-actions">
                    <StagePill status={row.workflow_status} type="purchase_request" />
                    <b className="ld-amount">{peso(row.total ?? row.estimated_amount)}</b>
                    <button type="button" className="program-btn" aria-expanded={open.has(row.id)} onClick={() => toggle(row.id)}>
                      {open.has(row.id) ? 'Hide items' : 'Show items'}
                    </button>
                  </div>
                </div>
                {open.has(row.id) && <PurchaseRequestItemsTable items={row.items} total={row.total} />}
              </li>
            );
          })}
        </ul>
      </LoadState>
    </section>
  );
}
