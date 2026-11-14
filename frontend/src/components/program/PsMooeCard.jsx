import ProgramPill from './ProgramPill';
import { formatPct, peso } from '../money';

const BLOCKS = [
  { key: 'ps', name: 'PS', full: 'Personal Services', color: '#6F1D2A' },
  { key: 'mooe', name: 'MOOE', full: 'Maintenance and Other Operating Expenses', color: '#BE9A3E' },
];

function pctOf(spent, allocated) {
  return allocated > 0 ? (spent / allocated) * 100 : 0;
}

/**
 * Allocated (line items) and spent (verified expenses) by category.
 * `summary` is the Overview payload's category_summary.
 */
export default function PsMooeCard({ summary }) {
  const blocks = BLOCKS.map((block) => {
    const row = summary?.[block.key] ?? {};
    const allocated = Number(row.allocated) || 0;
    const spent = Number(row.spent) || 0;
    return {
      ...block,
      allocated,
      spent,
      remaining: row.remaining != null ? Number(row.remaining) : allocated - spent,
      pct: pctOf(spent, allocated),
    };
  });
  const uncategorized = Number(summary?.uncategorized?.spent) || 0;
  const allocatedTotal = blocks.reduce((sum, block) => sum + block.allocated, 0);
  const spentTotal = blocks.reduce((sum, block) => sum + block.spent, 0) + uncategorized;
  const lineItemsTotal = Number(summary?.line_items_total ?? allocatedTotal) || 0;
  const approvedBudget = Number(summary?.approved_budget) || 0;
  const showBudgetNote = summary && summary.matches_budget === false;

  return (
    <section className="program-card pl-chart-card pl-cat-card">
      <div className="program-card-head">
        <div>
          <div className="program-card-title">PS vs MOOE</div>
          <div className="program-card-sub">Allocated and spent by category</div>
        </div>
      </div>

      <div className="pl-cat-list">
        {blocks.map((block) => (
          <div className="pl-cat" key={block.key}>
            <div className="pl-cat-top">
              <b title={block.full}>{block.name}</b>
              {block.pct > 100 && <ProgramPill value="Over budget" label="Over budget" tone="risk" icon="!" />}
            </div>
            <div className="pl-cat-amount">
              <b>{peso(block.spent)}</b> of {peso(block.allocated)}
            </div>
            <div
              className="pl-track pl-cat-track"
              role="progressbar"
              aria-label={`${block.name} spent`}
              aria-valuemin={0}
              aria-valuemax={100}
              aria-valuenow={Math.round(Math.min(block.pct, 100))}
            >
              <span style={{ width: `${Math.min(block.pct, 100)}%`, background: block.color }} />
            </div>
            <div className="pl-cat-meta">
              <span>{formatPct(block.pct)} used</span>
              <span>Remaining <b>{peso(block.remaining)}</b></span>
            </div>
          </div>
        ))}

        {uncategorized > 0 && (
          <div className="pl-cat-line">
            <span>Uncategorized</span>
            <b>{peso(uncategorized)}</b>
          </div>
        )}
      </div>

      <div className="pl-cat-total">
        <span>Total</span>
        <span><b>{peso(spentTotal)}</b> of {peso(allocatedTotal)}</span>
      </div>

      {showBudgetNote && (
        <p className="program-footnote pl-cat-note">
          Line items total {peso(lineItemsTotal)}; approved budget {peso(approvedBudget)}
        </p>
      )}
    </section>
  );
}
