import { useEffect, useState } from 'react';
import { fetchReviewItem } from '../../api/leader';
import ProgramPill from '../program/ProgramPill';
import DocumentList from './DocumentList';
import { peso } from '../money';
import PurchaseRequestItemsTable from '../PurchaseRequestItemsTable';
import { formatDate, formatDateTime } from '../../lib/datetime';

function fieldValue(field) {
  if (field.value == null || field.value === '') return '—';
  if (field.kind === 'money') return peso(field.value);
  if (field.kind === 'date') return formatDate(field.value);
  return field.value;
}

/**
 * Side panel with the full record and its documents. `target` is
 * { type, id } (the record is loaded fresh, at any stage). Approve / Return
 * act through the same handlers as the list. A returned expense the leader
 * recorded offers "Edit and resubmit" (onEdit); an approved or partly
 * fulfilled purchase request offers "Cancel request" with a reason
 * (onCancel; here only, never on list rows); others stay read-only.
 */
export default function RecordPanel({ projectId, target, refreshKey, busyKey, onApprove, onReturn, onEdit, onCancel, onClose }) {
  const key = target ? `${target.type}-${target.id}#${refreshKey}` : '';
  const [result, setResult] = useState(null); // { key, item } | { key, error }

  useEffect(() => {
    if (!key) return undefined;
    let alive = true;
    fetchReviewItem(projectId, target.type, target.id)
      .then((data) => { if (alive) setResult({ key, item: data.item }); })
      .catch((error) => {
        if (!alive) return;
        setResult({ key, error: error?.status === 404 ? 'This record is not part of this project.' : 'Could not load this record. Please try again.' });
      });
    return () => { alive = false; };
  }, [key, projectId, target?.type, target?.id]);

  useEffect(() => {
    if (!target) return undefined;
    const onKey = (event) => { if (event.key === 'Escape') onClose(); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [target, onClose]);

  if (!target) return null;
  const item = result?.key === key ? result.item : null;
  const error = result?.key === key ? result.error : null;
  const busy = item && busyKey === item.key;

  return (
    <div className="ld-panel-overlay" role="presentation" onClick={onClose}>
      <aside
        className="ld-panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ld-panel-title"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="ld-panel-head">
          <div>
            <div className="ld-panel-eyebrow">{item?.type_label ?? 'Record'}</div>
            <h2 className="ld-panel-title" id="ld-panel-title">{item?.title ?? 'Loading…'}</h2>
          </div>
          <button type="button" className="program-icon-btn" onClick={onClose} aria-label="Close panel">×</button>
        </div>

        {error && <div className="program-banner error" role="alert">{error}</div>}
        {!item && !error && <p className="ld-muted" aria-busy="true">Loading…</p>}

        {item && (
          <>
            <div className="ld-panel-status">
              <ProgramPill value={item.workflow_label} />
              {item.amount != null && <b className="ld-amount">{peso(item.amount)}</b>}
            </div>
            {item.waiting_text && <p className="ld-waiting"><span aria-hidden="true">◷</span> {item.waiting_text}</p>}
            {item.return_reason && item.workflow_status === 'returned' && <p className="ld-reason">Reason: “{item.return_reason}”</p>}

            <dl className="ld-fields">
              <div><dt>Submitted by</dt><dd>{item.submitted_by?.name ?? '—'}</dd></div>
              <div><dt>Submitted</dt><dd>{formatDateTime(item.submitted_at)}</dd></div>
              {item.fields.map((field) => (
                <div key={field.label}><dt>{field.label}</dt><dd>{fieldValue(field)}</dd></div>
              ))}
              {item.reviewed_by && (
                <div><dt>Project Leader decision</dt><dd>{item.reviewed_by.name} · {formatDateTime(item.reviewed_at)}</dd></div>
              )}
            </dl>
            {Array.isArray(item.pr_items) && (
              <PurchaseRequestItemsTable items={item.pr_items} total={item.pr_total} showStatus={['endorsed', 'fulfilled', 'cancelled'].includes(item.workflow_status)} />
            )}

            <h3 className="ld-panel-sub">Documents</h3>
            <DocumentList type={item.type} id={item.id} />

            {item.can_edit && onEdit && (
              <div className="ld-panel-actions">
                <button type="button" className="program-btn primary" onClick={() => onEdit(item)}>Edit and resubmit</button>
              </div>
            )}
            {item.can_review && (
              <div className="ld-panel-actions">
                <button type="button" className="program-btn" disabled={busy} onClick={() => onReturn(item)}>Return</button>
                <button type="button" className="program-btn primary" disabled={busy} onClick={() => onApprove(item)}>
                  {busy ? 'Saving…' : 'Approve'}
                </button>
              </div>
            )}
            {item.type === 'purchase_request' && item.can_cancel && onCancel && (
              <div className="ld-panel-actions">
                <button type="button" className="program-btn danger" disabled={busy} onClick={() => onCancel(item)}>Cancel request</button>
              </div>
            )}
          </>
        )}
      </aside>
    </div>
  );
}
