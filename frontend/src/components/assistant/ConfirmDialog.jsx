import { useState } from 'react';

/**
 * Confirmation dialog. With `reasonLabel` a reason is required (for example
 * cancelling an approved purchase request); the server checks it too.
 */
export default function ConfirmDialog({ title, body, confirmLabel, busyLabel = 'Saving…', danger = false, reasonLabel = null, busy, error, onClose, onConfirm }) {
  const [reason, setReason] = useState('');
  const [localError, setLocalError] = useState('');

  function submit(event) {
    event.preventDefault();
    if (reasonLabel && !reason.trim()) {
      setLocalError('A reason is required.');
      return;
    }
    setLocalError('');
    onConfirm(reason.trim());
  }

  return (
    <div className="program-modal-overlay" role="presentation" onClick={() => { if (!busy) onClose(); }}>
      <form
        className="program-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ra-confirm-title"
        onClick={(event) => event.stopPropagation()}
        onSubmit={submit}
        noValidate
      >
        <div className="program-modal-head">
          <div>
            <div className="program-modal-title" id="ra-confirm-title">{title}</div>
            {body && <div className="program-card-sub">{body}</div>}
          </div>
          <button type="button" className="program-icon-btn" onClick={onClose} aria-label="Close" disabled={busy}>×</button>
        </div>

        {reasonLabel && (
          <div className="program-field">
            <label htmlFor="ra-confirm-reason">{reasonLabel} <span className="ld-required">required</span></label>
            <textarea
              id="ra-confirm-reason"
              className="program-textarea"
              rows={3}
              maxLength={2000}
              value={reason}
              onChange={(event) => { setReason(event.target.value); if (localError) setLocalError(''); }}
              disabled={busy}
              aria-invalid={Boolean(localError)}
              autoFocus
            />
          </div>
        )}
        {(localError || error) && <div className="field-err" role="alert">{localError || error}</div>}

        <div className="program-modal-actions">
          <button type="button" className="program-btn" onClick={onClose} disabled={busy}>Keep it</button>
          <button type="submit" className={`program-btn ${danger ? 'danger' : 'primary'}`} disabled={busy}>{busy ? busyLabel : confirmLabel}</button>
        </div>
      </form>
    </div>
  );
}
