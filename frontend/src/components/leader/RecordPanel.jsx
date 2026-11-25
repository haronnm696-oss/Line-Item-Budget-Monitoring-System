import { useEffect, useState } from 'react';
import { fetchReviewItem } from '../../api/leader';
import ProgramPill from '../program/ProgramPill';
import DocumentList from './DocumentList';
import PurchaseRequestItemsTable from '../PurchaseRequestItemsTable';
import PurchaseRequestDownload from '../PurchaseRequestDownload';
import DetailModal, { DetailNote } from '../DetailModal';
import { formatDate, formatDateTime } from '../../lib/datetime';
import { waitedText } from '../../lib/waited';
import { peso } from '../money';

const TYPE_ICON = { expense: '▤', payroll: '₱', purchase_request: '◇' };

function fieldValue(field) {
  if (field.value == null || field.value === '') return '—';
  if (field.kind === 'money') return peso(field.value);
  if (field.kind === 'date') return formatDate(field.value);
  return field.value;
}

/**
 * Detail modal with the full record and its documents. `target` is
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

  if (!target) return null;
  const item = result?.key === key ? result.item : null;
  const error = result?.key === key ? result.error : null;
  const busy = item && busyKey === item.key;

  const editAction = item && item.can_edit && onEdit && (
    <button type="button" className="program-btn primary" onClick={() => onEdit(item)}>Edit and resubmit</button>
  );
  const reviewActions = item && item.can_review && (
    <>
      <button type="button" className="program-btn" disabled={busy} onClick={() => onReturn(item)}>Return</button>
      <button type="button" className="program-btn primary" disabled={busy} onClick={() => onApprove(item)}>
        {busy ? 'Saving…' : 'Approve'}
      </button>
    </>
  );
  const cancelAction = item && item.type === 'purchase_request' && item.can_cancel && onCancel && (
    <button type="button" className="program-btn danger" disabled={busy} onClick={() => onCancel(item)}>Cancel request</button>
  );
  const hasActions = Boolean(editAction || reviewActions || cancelAction);

  return (
    <DetailModal
      titleId="ld-panel-title"
      title={item?.title ?? 'Loading…'}
      eyebrow={item ? [item.type_label, item.reference].filter(Boolean).join(' · ') : 'Record'}
      icon={TYPE_ICON[item?.type ?? target.type] ?? '▤'}
      chips={item && (
        <>
          <ProgramPill value={item.workflow_label} />
          {item.can_review && <span className="dm-wait">{waitedText(item.submitted_at)}</span>}
          {item.type_label && <ProgramPill value={item.type_label} />}
        </>
      )}
      amount={item?.amount ?? null}
      hint={reviewActions ? 'Check the documents before you decide.' : editAction ? 'Fix what was returned, then resubmit.' : 'Cancelling asks you for a reason first.'}
      actions={hasActions ? <>{editAction}{reviewActions}{cancelAction}</> : null}
      onClose={onClose}
    >
      {error && <div className="program-banner error" role="alert">{error}</div>}
      {!item && !error && <p className="ld-muted" aria-busy="true">Loading…</p>}

      {item && (
        <>
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
          {item.return_reason && item.workflow_status === 'returned' && (
            <DetailNote label="Returned" tone="risk"><span aria-hidden="true">↩</span> Reason: “{item.return_reason}”</DetailNote>
          )}
          {item.waiting_text && <p className="ld-waiting"><span aria-hidden="true">◷</span> {item.waiting_text}</p>}

          {Array.isArray(item.pr_items) && (
            <PurchaseRequestItemsTable items={item.pr_items} total={item.pr_total} showStatus={['endorsed', 'fulfilled', 'cancelled'].includes(item.workflow_status)} />
          )}
          {item.type === 'purchase_request' && (
            <div className="ld-panel-actions pr-download">
              <PurchaseRequestDownload requestId={item.id} reference={item.reference ?? `PR-${item.id}`} />
            </div>
          )}

          <h3 className="ld-panel-sub">Documents</h3>
          <DocumentList type={item.type} id={item.id} />
        </>
      )}
    </DetailModal>
  );
}
