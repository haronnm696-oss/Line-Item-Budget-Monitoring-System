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
  const [approveTarget, setApproveTarget] = useState(null);
  const [confirming, setConfirming] = useState(false);
  const [rejectTarget, setRejectTarget] = useState(null);
  const [reason, setReason] = useState('');
  const items = Array.isArray(approvals) ? approvals : [];

  function closeReject() {
    setRejectTarget(null);
    setReason('');
  }

  function closeApprove() {
    if (confirming) return;
    setApproveTarget(null);
  }

  async function submitApprove() {
    if (!approveTarget || confirming) return;
    setConfirming(true);
    try {
      await onApprove(approveTarget.id);
    } finally {
      setConfirming(false);
      setApproveTarget(null);
    }
  }

  function submitReject(event) {
    event.preventDefault();
    if (!rejectTarget) return;
    onReject(rejectTarget.id, reason);
    closeReject();
  }

  const approveAmount = Number(approveTarget?.amount) || 0;
  const needsStrongWarning = approveTarget?.type === 'disbursement' || approveAmount >= 100000;

  return (
    <>
      <Panel
        title="Quick Approvals"
        hint="Pending fund releases and budget realignments"
        action={<Chip tone="warn">{items.length} pending</Chip>}
        style={{ height: '100%' }}
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
                    onClick={() => setApproveTarget(approval)}
                  >
                    Approve
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

      {approveTarget && (
        <div
          className="modal-overlay"
          onClick={closeApprove}
          role="dialog"
          aria-modal="true"
          aria-labelledby="confirm-approve-title"
        >
          <div
            className="modal-panel"
            style={{ maxWidth: 520 }}
            onClick={(event) => event.stopPropagation()}
          >
            <div className="modal-head">
              <h3 id="confirm-approve-title">Are you sure?</h3>
              <button type="button" className="modal-close" onClick={closeApprove} aria-label="Close" disabled={confirming}>×</button>
            </div>
            <div style={{ padding: 20 }}>
              <p style={{ margin: '0 0 12px', fontSize: 13, color: 'var(--muted)' }}>
                You are about to approve this request:
              </p>
              <div className="confirm-card">
                <div className="approval-title-row">
                  <b>{approveTarget.title}</b>
                  <Chip tone={approveTarget.type === 'budget_realignment' ? 'warn' : 'brand'}>
                    {approveTarget.type_label}
                  </Chip>
                </div>
                {approveTarget.description && (
                  <p style={{ margin: '4px 0 0', fontSize: 12, color: 'var(--muted)', lineHeight: 1.45 }}>
                    {approveTarget.description}
                  </p>
                )}
                <div className="confirm-meta">
                  {approveTarget.amount != null && (
                    <span className="confirm-amount">{peso(approveTarget.amount)}</span>
                  )}
                  {approveTarget.requested_by && <span>Requested by {approveTarget.requested_by}</span>}
                  {approveTarget.tranche_name && <span>{approveTarget.tranche_name}</span>}
                </div>
              </div>

              {needsStrongWarning && (
                <div className="banner over" style={{ margin: '14px 0 0' }}>
                  This releases <b>{peso(approveAmount)}</b>
                  {approveTarget.tranche_name ? ` for ${approveTarget.tranche_name}` : ''} and cannot be undone.
                </div>
              )}
            </div>
            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, padding: '0 20px 20px' }}>
              <button type="button" className="btn" onClick={closeApprove} disabled={confirming}>Cancel</button>
              <button type="button" className="btn approve" onClick={submitApprove} disabled={confirming}>
                {confirming ? 'Approving…' : 'Yes, approve'}
              </button>
            </div>
          </div>
        </div>
      )}

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
