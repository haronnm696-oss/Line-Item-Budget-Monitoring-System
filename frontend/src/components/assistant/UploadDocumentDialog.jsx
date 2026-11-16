import { useEffect, useState } from 'react';
import { ENDPOINTS } from '../../api/endpoints';
import { apiGet } from '../../api/http';
import { peso } from '../money';
import UploadDialog from './UploadDialog';

const TYPES = {
  expense: ['receipt', 'invoice', 'other'],
  purchase_request: ['quotation', 'invoice', 'other'],
  payroll: ['payroll_file', 'other'],
};

/**
 * "Upload document" from the Overview: choose one of the records that still
 * accepts documents (your drafts and returned records, and verified
 * expenses missing a receipt), then upload to it with UploadDialog.
 */
export default function UploadDocumentDialog({ projectId, missingReceipts = [], onClose, onDone }) {
  const [records, setRecords] = useState(null); // null = loading
  const [error, setError] = useState('');
  const [choice, setChoice] = useState('');
  const [picked, setPicked] = useState(null);

  useEffect(() => {
    let alive = true;
    const url = (tab) => `${ENDPOINTS.mySubmissions(projectId)}?tab=${tab}`;
    Promise.all([apiGet(url('drafts')), apiGet(url('returned'))])
      .then(([drafts, returned]) => {
        if (!alive) return;
        const missing = missingReceipts.map((row) => ({
          key: `expense-${row.id}`, type: 'expense', id: row.id, type_label: 'Expense',
          reference: row.reference_no || `EXP-${row.id}`, title: row.description, amount: row.amount, missingReceipt: true,
        }));
        setRecords([...(returned.items ?? []), ...(drafts.items ?? []), ...missing]);
      })
      .catch(() => { if (alive) setError('Could not load your records. Please try again.'); });
    return () => { alive = false; };
  }, [projectId, missingReceipts]);

  if (picked) {
    return (
      <UploadDialog
        record={picked}
        types={picked.missingReceipt ? ['receipt'] : TYPES[picked.type]}
        title={picked.missingReceipt ? 'Upload missing receipt' : 'Upload document'}
        onClose={onClose}
        onDone={onDone}
      />
    );
  }

  const chosen = records?.find((row) => row.key === choice);

  return (
    <div className="program-modal-overlay" role="presentation" onClick={onClose}>
      <form
        className="program-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ra-pick-title"
        onClick={(event) => event.stopPropagation()}
        onSubmit={(event) => { event.preventDefault(); if (chosen) setPicked(chosen); }}
      >
        <div className="program-modal-head">
          <div>
            <div className="program-modal-title" id="ra-pick-title">Upload document</div>
            <div className="program-card-sub">Documents can be added to drafts, returned records and verified expenses missing a receipt</div>
          </div>
          <button type="button" className="program-icon-btn" onClick={onClose} aria-label="Close">×</button>
        </div>

        {error && <div className="program-banner error" role="alert">{error}</div>}
        {!error && records === null && <p className="ld-muted" aria-busy="true">Loading your records…</p>}
        {records && records.length === 0 && <p className="ld-muted">Nothing accepts documents right now. Record an expense or a purchase request first.</p>}
        {records && records.length > 0 && (
          <div className="program-field">
            <label htmlFor="ra-pick-record">Record</label>
            <select id="ra-pick-record" className="program-select" value={choice} onChange={(event) => setChoice(event.target.value)}>
              <option value="">Choose a record</option>
              {records.map((row) => (
                <option key={row.key} value={row.key}>
                  {row.type_label} · {row.reference} · {row.title}{row.amount != null ? ` — ${peso(row.amount)}` : ''}
                  {row.missingReceipt ? ' (missing receipt)' : row.workflow_status === 'returned' ? ' (returned)' : ' (draft)'}
                </option>
              ))}
            </select>
          </div>
        )}

        <div className="program-modal-actions">
          <button type="button" className="program-btn" onClick={onClose}>Cancel</button>
          <button type="submit" className="program-btn primary" disabled={!chosen}>Next</button>
        </div>
      </form>
    </div>
  );
}
