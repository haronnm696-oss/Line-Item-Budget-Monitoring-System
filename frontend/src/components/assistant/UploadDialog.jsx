import { useState } from 'react';
import { uploadDocument } from '../../api/assistant';
import { errorText } from '../../api/leader';
import { peso } from '../money';

const TYPE_LABEL = {
  receipt: 'Receipt',
  invoice: 'Invoice',
  quotation: 'Quotation',
  payroll_file: 'Payroll file',
  other: 'Other',
};

/**
 * Upload one document to a record. `types` lists the document types the
 * record accepts (the first is the default). `record` is { type, id,
 * reference?, title?, amount? }.
 */
export default function UploadDialog({ record, types, title = 'Upload document', onClose, onDone }) {
  const [documentType, setDocumentType] = useState(types[0]);
  const [file, setFile] = useState(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  async function submit(event) {
    event.preventDefault();
    if (!file) {
      setError('Choose a PDF, JPG or PNG file.');
      return;
    }
    setBusy(true);
    setError('');
    try {
      await uploadDocument(record.type, record.id, file, documentType);
      onDone(`${TYPE_LABEL[documentType] ?? 'Document'} uploaded to ${record.reference ?? 'the record'}.`);
    } catch (failure) {
      setError(errorText(failure, 'Could not upload. Please try again.'));
      setBusy(false);
    }
  }

  return (
    <div className="program-modal-overlay" role="presentation" onClick={() => { if (!busy) onClose(); }}>
      <form
        className="program-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ra-upload-title"
        onClick={(event) => event.stopPropagation()}
        onSubmit={submit}
        noValidate
      >
        <div className="program-modal-head">
          <div>
            <div className="program-modal-title" id="ra-upload-title">{title}</div>
            <div className="program-card-sub">
              {[record.reference, record.title, record.amount != null ? peso(record.amount) : null].filter(Boolean).join(' · ')}
            </div>
          </div>
          <button type="button" className="program-icon-btn" onClick={onClose} aria-label="Close" disabled={busy}>×</button>
        </div>

        {types.length > 1 && (
          <div className="program-field">
            <label htmlFor="ra-upload-type">Document type</label>
            <select id="ra-upload-type" className="program-select" value={documentType} onChange={(event) => setDocumentType(event.target.value)} disabled={busy}>
              {types.map((type) => <option key={type} value={type}>{TYPE_LABEL[type] ?? type}</option>)}
            </select>
          </div>
        )}
        <div className="program-field">
          <label htmlFor="ra-upload-file">File <span className="ld-muted">PDF, JPG or PNG, up to 10 MB</span></label>
          <input id="ra-upload-file" type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={(event) => { setFile(event.target.files?.[0] ?? null); setError(''); }} disabled={busy} />
        </div>
        {error && <div className="field-err" role="alert">{error}</div>}

        <div className="program-modal-actions">
          <button type="button" className="program-btn" onClick={onClose} disabled={busy}>Cancel</button>
          <button type="submit" className="program-btn primary" disabled={busy}>{busy ? 'Uploading…' : 'Upload'}</button>
        </div>
      </form>
    </div>
  );
}
