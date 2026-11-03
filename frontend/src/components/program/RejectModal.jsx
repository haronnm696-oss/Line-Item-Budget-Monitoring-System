import { useState } from 'react';
import { peso } from '../money';

export default function RejectModal({ approval, busy, error, onClose, onConfirm }) {
  const [remarks, setRemarks] = useState('');
  const [localError, setLocalError] = useState('');

  if (!approval) return null;

  function submit(event) {
    event.preventDefault();
    if (!remarks.trim()) {
      setLocalError('Remarks are required when rejecting a request.');
      return;
    }
    setLocalError('');
    onConfirm(remarks.trim());
  }

  return (
    <div
      className="modal-overlay"
      onClick={() => {
        if (!busy) onClose();
      }}
      role="dialog"
      aria-modal="true"
      aria-labelledby="pd-reject-title"
    >
      <form
        className="modal-panel"
        style={{ maxWidth: 520 }}
        onClick={(event) => event.stopPropagation()}
        onSubmit={submit}
      >
        <div className="modal-head">
          <h3 id="pd-reject-title">Reject request</h3>
          <button
            type="button"
            className="modal-close"
            onClick={onClose}
            aria-label="Close"
            disabled={busy}
          >
            ×
          </button>
        </div>

        <div style={{ padding: 20 }}>
          <p style={{ margin: '0 0 10px', lineHeight: 1.45 }}>
            <b>{approval.title}</b>
          </p>
          <div className="pd-reject-meta">
            <span>{approval.project}</span>
            <span>{approval.funder}</span>
            {approval.amount != null && <b>{peso(approval.amount)}</b>}
          </div>

          <div className="field" style={{ marginTop: 16, marginBottom: 0 }}>
            <label htmlFor="pd-reject-remarks">
              Remarks <span className="pd-required">required</span>
            </label>
            <textarea
              id="pd-reject-remarks"
              rows={5}
              value={remarks}
              onChange={(event) => {
                setRemarks(event.target.value);
                if (localError) setLocalError('');
              }}
              placeholder="Explain what must be corrected before this request is resubmitted…"
              disabled={busy}
              autoFocus
            />
            {(localError || error) && <div className="field-err">{localError || error}</div>}
          </div>
        </div>

        <div className="pd-modal-actions">
          <button type="button" className="btn" onClick={onClose} disabled={busy}>
            Cancel
          </button>
          <button type="submit" className="btn reject" disabled={busy}>
            {busy ? 'Rejecting…' : 'Reject request'}
          </button>
        </div>
      </form>
    </div>
  );
}
