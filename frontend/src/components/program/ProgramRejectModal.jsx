import { useState } from 'react';

export default function ProgramRejectModal({ approval, busy, error, onClose, onConfirm }) {
  const [reason, setReason] = useState('');
  const [localError, setLocalError] = useState('');

  if (!approval) return null;

  function handleSubmit(event) {
    event.preventDefault();
    if (!reason.trim()) {
      setLocalError('A reason for rejection is required.');
      return;
    }
    setLocalError('');
    onConfirm(reason.trim());
  }

  return (
    <div
      className="program-modal-overlay"
      role="dialog"
      aria-modal="true"
      aria-labelledby="program-reject-title"
      onClick={() => !busy && onClose()}
    >
      <div className="program-modal" onClick={(event) => event.stopPropagation()}>
        <div className="program-modal-head">
          <div>
            <div className="program-modal-title" id="program-reject-title">Reject request</div>
            <p className="program-card-sub">
              {approval.title} · {approval.project}
            </p>
          </div>
          <button
            type="button"
            className="program-icon-btn"
            onClick={() => !busy && onClose()}
            aria-label="Close"
          >
            ×
          </button>
        </div>

        <form onSubmit={handleSubmit}>
          <div className="program-field">
            <label htmlFor="program-reject-reason">Reason for rejection (required)</label>
            <textarea
              id="program-reject-reason"
              className="program-textarea"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              placeholder="Tell the Project Leader what to fix"
              aria-describedby={localError || error ? 'program-reject-error' : undefined}
              autoFocus
            />
          </div>

          {(localError || error) && (
            <p className="program-error" id="program-reject-error" aria-live="polite">
              {localError || error}
            </p>
          )}

          <div className="program-modal-actions">
            <button type="button" className="program-btn" onClick={onClose} disabled={busy}>
              Cancel
            </button>
            <button type="submit" className="program-btn primary" disabled={busy}>
              {busy ? 'Rejecting…' : 'Confirm rejection'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
