import Panel from '../Panel';
import Chip from '../Chip';
import { peso } from '../money';

const KIND_ICON = {
  tranche: '₱',
  realignment: '⇄',
  liquidation: '▤',
  other: '•',
};

function waitingLabel(days) {
  const value = Number(days) || 0;
  if (value <= 0) return 'Today';
  return `${value}d waiting`;
}

function waitingTone(days) {
  const value = Number(days) || 0;
  if (value >= 7) return 'over';
  if (value >= 3) return 'warn';
  return 'ok';
}

export default function ApprovalInbox({ approvals, busyId, onApprove, onReject }) {
  const items = Array.isArray(approvals) ? approvals : [];

  return (
    <Panel
      title="Approval inbox"
      hint="Oldest first · decisions refresh every section"
      action={<Chip tone={items.length ? 'warn' : 'ok'}>{items.length} pending</Chip>}
      style={{ height: '100%' }}
    >
      {items.length === 0 ? (
        <div className="empty-state">No pending approvals. You are all caught up.</div>
      ) : (
        <div className="pd-approval-list">
          {items.map((approval) => {
            const busy = busyId === approval.id;

            return (
              <div className="pd-approval" key={approval.id}>
                <div className="pd-approval-icon" aria-hidden="true">
                  {KIND_ICON[approval.kind] ?? KIND_ICON.other}
                </div>
                <div className="pd-approval-main">
                  <div className="pd-approval-top">
                    <b>{approval.title}</b>
                    <Chip tone={approval.kind === 'realignment' ? 'warn' : 'brand'}>
                      {approval.type_label}
                    </Chip>
                  </div>
                  <div className="pd-approval-meta">
                    <span>{approval.project}</span>
                    <span aria-hidden="true">·</span>
                    <span>{approval.funder}</span>
                    {approval.amount != null && (
                      <span className="amt">{peso(approval.amount)}</span>
                    )}
                    {approval.requested_by && <span>by {approval.requested_by}</span>}
                  </div>
                </div>
                <div className="pd-approval-right">
                  <span className={`pd-age ${waitingTone(approval.days_waiting)}`}>
                    {waitingLabel(approval.days_waiting)}
                  </span>
                  <div className="pd-approval-actions">
                    <button
                      type="button"
                      className="btn sm approve"
                      disabled={busy}
                      onClick={() => onApprove(approval)}
                    >
                      {busy ? '…' : 'Approve'}
                    </button>
                    <button
                      type="button"
                      className="btn sm reject"
                      disabled={busy}
                      onClick={() => onReject(approval)}
                    >
                      Reject
                    </button>
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </Panel>
  );
}
