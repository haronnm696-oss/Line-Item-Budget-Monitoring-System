import Panel from './Panel';
import Chip from './Chip';
import { peso } from './money';

const RING_COLOR = {
  ok: 'var(--ok)',
  warn: 'var(--warn)',
  over: 'var(--over)',
  neutral: 'var(--line-strong)',
};

function formatDate(value) {
  if (!value) return '';
  return new Date(`${value}T00:00:00`).toLocaleDateString('en-PH', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
}

export default function ComplianceTracker({ compliance }) {
  if (!compliance) return null;

  const total = Number(compliance.total_expenses) || 0;
  const pct = Number(compliance.compliance_pct) || 0;
  const tone = compliance.tone || 'neutral';
  const missing = compliance.missing_items ?? [];

  return (
    <Panel
      title="Document & Receipt Compliance"
      hint="Audit readiness across recorded expenses"
      action={<Chip tone={tone}>{compliance.status}</Chip>}
      style={{ height: '100%' }}
    >
      <div className="compliance-body">
        <div className="compliance-hero">
          <div
            className="compliance-ring"
            style={{ background: `conic-gradient(${RING_COLOR[tone] || RING_COLOR.neutral} ${pct * 3.6}deg, var(--line) 0)` }}
            role="img"
            aria-label={`${pct}% of expenses have digitized receipts`}
          >
            <div className="compliance-ring-inner">
              <b>{pct}%</b>
              <span>with receipts</span>
            </div>
          </div>
          <div className="compliance-metrics">
            <div>
              <span>Digitized receipts</span>
              <b>{compliance.with_receipt} of {total}</b>
            </div>
            <div>
              <span>Missing receipts</span>
              <b className={compliance.missing_receipt > 0 ? 'over' : 'ok'}>{compliance.missing_receipt}</b>
            </div>
            <div>
              <span>Verified expenses</span>
              <b>{compliance.verified}</b>
            </div>
            <div>
              <span>Pending review</span>
              <b>{compliance.pending}</b>
            </div>
          </div>
        </div>

        <div className="util compliance-bar">
          <div className="track">
            <div className={`fill ${tone}`} style={{ width: `${Math.max(0, Math.min(100, pct))}%` }} />
          </div>
        </div>

        {missing.length > 0 ? (
          <div className="compliance-missing">
            <div className="compliance-subhead">Recent items missing supporting documents</div>
            {missing.map((item) => (
              <div className="missing-item" key={item.id}>
                <div>
                  <b>{item.reference_no || `Expense #${item.id}`}</b>
                  <span>{item.description}</span>
                </div>
                <div className="missing-meta">
                  <span>{formatDate(item.expense_date)}</span>
                  <b>{peso(item.amount)}</b>
                  <Chip tone={item.status === 'Verified' ? 'warn' : 'neutral'}>{item.status}</Chip>
                </div>
              </div>
            ))}
          </div>
        ) : total > 0 ? (
          <div className="compliance-clear">Every recorded expense has a digitized receipt on file.</div>
        ) : (
          <div className="empty-state">No expenses have been recorded yet.</div>
        )}
      </div>
    </Panel>
  );
}
