import { useState } from 'react';
import Panel from './Panel';
import Chip from './Chip';
import { peso } from './money';

const TYPE_ICON = {
  disbursement: '₱',
  budget_realignment: '⇄',
  payroll: '▤',
};

function formatWhen(value) {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  return date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}

export default function QuickApprovals({ approvals, busyId, onApprove, onReject }) {
  const [rejectTarget, setRejectTarget] = useState(null);
  const [reason, setReason] = useState('');
  const items = Array.isArray(approvals) ? approvals : [];

  function closeReject() {
    setRejectTarget(null);
    setReason('');
  }

  function submitReject(event) {
    event.preventDefault();
    if (!rejectTarget) return;
    onReject(rejectTarget.id, reason);
    closeReject();
  }

  return (
    <>
      <Panel
        title="Quick Approvals"
        hint="Pending fund releases and budget realignments"
        action={<Chip tone="warn">{items.length} pending</Chip>}
      >
        {items.length === 0 ? (
          <div className="empty-state">All caught up — no pending approvals.</div>
        ) : (
          <div className="approval-list">
            {items.map((approval) => (
              <div className="approve-line approval-card" key={approval.id}>
                <div className="approval-icon" aria-hidden="true">
                  {TYPE_ICON[approval.type] || '•'}
                </div>
                <div className="a-info approval-main">
                  <div className="approval-title-row">
                    <b>{approval.title}</b>
                    <Chip tone={approval.type === 'budget_realignment' ? 'warn' : 'brand'}>
                      {approval.type_label}
                    </Chip>
                  </div>
                  {approval.description && <p>{approval.description}</p>}
                  <small>
                    {approval.amount != null && <span className="approval-amount">{peso(approval.amount)}</span>}
                    {approval.requested_by && <span>Requested by {approval.requested_by}</span>}
                    {approval.tranche_name && <span>{approval.tranche_name}</span>}
                    {approval.created_at && <span>{formatWhen(approval.created_at)}</span>}
                  </small>
                </div>
                <div className="a-actions">
                  <button
                    type="button"
                    className="btn sm approve"
                    disabled={busyId === approval.id}
                    onClick={() => onApprove(approval.id)}
                  >
                    {busyId === approval.id ? 'Working…' : 'Approve'}
                  </button>
                  <button
                    type="button"
                    className="btn sm reject"
                    disabled={busyId === approval.id}
                    onClick={() => {
                      setRejectTarget(approval);
                      setReason('');
                    }}
                  >
                    Reject
                  </button>
                </div>
              </div>
            ))}
          </div>
        )}
      </Panel>

      {rejectTarget && (
        <div
          className="modal-overlay"
          onClick={closeReject}
          role="dialog"
          aria-modal="true"
          aria-label="Reject approval request"
        >
          <form
            className="modal-panel"
            style={{ maxWidth: 480 }}
            onClick={(event) => event.stopPropagation()}
            onSubmit={submitReject}
          >
            <div className="modal-head">
              <h3>Reject request</h3>
              <button type="button" className="modal-close" onClick={closeReject} aria-label="Close">×</button>
            </div>
            <div style={{ padding: 20 }}>
              <p style={{ margin: '0 0 14px' }}><b>{rejectTarget.title}</b></p>
              <div className="field">
                <label htmlFor="reject-reason">Reason (optional)</label>
                <textarea
                  id="reject-reason"
                  rows={4}
                  value={reason}
                  onChange={(event) => setReason(event.target.value)}
                  placeholder="Explain why this request is being rejected…"
                />
              </div>
            </div>
            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, padding: '0 20px 20px' }}>
              <button type="button" className="btn" onClick={closeReject}>Cancel</button>
              <button type="submit" className="btn reject">Reject request</button>
            </div>
          </form>
        </div>
      )}
    </>
  );
}
