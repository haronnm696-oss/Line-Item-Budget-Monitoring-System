import { useState } from 'react';
import { peso } from '../money';

/** Return a record to its maker; the reason is required (the server checks it too). */
export default function ReturnDialog({ item, busy, error, onClose, onConfirm }) {
  const [reason, setReason] = useState('');
  const [localError, setLocalError] = useState('');

  if (!item) return null;

  function submit(event) {
    event.preventDefault();
    if (!reason.trim()) {
      setLocalError('A reason is required to return it.');
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
        aria-labelledby="ld-return-title"
        onClick={(event) => event.stopPropagation()}
        onSubmit={submit}
      >
        <div className="program-modal-head">
          <div>
            <div className="program-modal-title" id="ld-return-title">Return {item.type_label.toLowerCase()}</div>
            <div className="program-card-sub">{item.title}{item.amount != null ? ` · ${peso(item.amount)}` : ''}</div>
          </div>
          <button type="button" className="program-icon-btn" onClick={onClose} aria-label="Close" disabled={busy}>×</button>
        </div>

        <div className="program-field">
          <label htmlFor="ld-return-reason">Reason <span className="ld-required">required</span></label>
          <textarea
            id="ld-return-reason"
            autoComplete="off"
            className="program-textarea"
            rows={4}
            value={reason}
            onChange={(event) => { setReason(event.target.value); if (localError) setLocalError(''); }}
            placeholder="What must be corrected before it is submitted again"
            disabled={busy}
            aria-invalid={Boolean(localError || error)}
            aria-describedby="ld-return-error"
            autoFocus
          />
          {(localError || error) && <div className="field-err" id="ld-return-error" role="alert">{localError || error}</div>}
        </div>
        <p className="ld-muted">{item.submitted_by?.name ?? 'The person who submitted it'} is notified with your reason.</p>

        <div className="program-modal-actions">
          <button type="button" className="program-btn" onClick={onClose} disabled={busy}>Cancel</button>
          <button type="submit" className="program-btn primary" disabled={busy}>{busy ? 'Returning…' : 'Return'}</button>
        </div>
      </form>
    </div>
  );
}
