import { useEffect, useState } from 'react';
import { fetchMyItem } from '../../api/assistant';
import { returnedLine } from '../../lib/assistantStatus';
import { formatDate, formatDateTime } from '../../lib/datetime';
import DocumentList from '../leader/DocumentList';
import { peso } from '../money';
import PurchaseRequestItemsTable from '../PurchaseRequestItemsTable';
import PurchaseRequestDownload from '../PurchaseRequestDownload';
import DetailModal, { DetailNote } from '../DetailModal';
import StagePill from './StagePill';

const TYPE_ICON = { expense: '▤', payroll: '₱', purchase_request: '◇' };

function fieldValue(field) {
  if (field.value == null || field.value === '') return '—';
  if (field.kind === 'money') return peso(field.value);
  if (field.kind === 'date') return formatDate(field.value);
  return field.value;
}

/**
 * Detail modal with one of the Research Assistant's records (loaded fresh,
 * any stage) and its documents. The buttons follow the server's can_*
 * flags; each one calls the page's useRecordActions().
 */
export default function AssistantRecordPanel({ projectId, target, refreshKey, actions, onClose }) {
  const key = target ? `${target.type}-${target.id}#${refreshKey}` : '';
  const [result, setResult] = useState(null); // { key, item } | { key, error }

  useEffect(() => {
    if (!key) return undefined;
    let alive = true;
    fetchMyItem(projectId, target.type, target.id)
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
  const busy = Boolean(item && actions.busyKey === item.key);
  const returned = returnedLine(item);
  const editable = item && (item.can_edit || item.can_delete || item.can_submit || item.can_cancel || item.can_attach_receipt);

  const footer = editable ? (
    <>
      {item.can_delete && (
        <button type="button" className="program-btn danger" disabled={busy} onClick={() => actions.remove(item)}>Delete</button>
      )}
      {item.can_cancel && (
        <button type="button" className="program-btn danger" disabled={busy} onClick={() => actions.cancel(item)}>Cancel request</button>
      )}
      {item.can_attach_receipt && (
        <button type="button" className="program-btn primary" disabled={busy} onClick={() => actions.upload(item, { types: ['receipt'], title: 'Upload missing receipt' })}>
          Upload receipt
        </button>
      )}
      {item.can_edit && (
        <button type="button" className={`program-btn${item.can_submit && item.workflow_status === 'draft' ? '' : ' primary'}`} disabled={busy} onClick={() => actions.edit(item)}>
          {item.workflow_status === 'returned' ? 'Edit and resubmit' : (item.type === 'payroll' ? 'Open payroll' : 'Edit')}
        </button>
      )}
      {item.can_submit && item.workflow_status === 'draft' && (
        <button type="button" className="program-btn primary" disabled={busy} onClick={() => actions.submit(item)}>
          {busy ? 'Submitting…' : 'Submit'}
        </button>
      )}
    </>
  ) : null;

  return (
    <DetailModal
      titleId="ra-panel-title"
      title={item?.title ?? 'Loading…'}
      eyebrow={item ? `${item.type_label} · ${item.reference}` : 'Record'}
      icon={TYPE_ICON[item?.type ?? target.type] ?? '▤'}
      chips={item && (
        <StagePill status={item.workflow_status} type={item.type === 'purchase_request' ? 'purchase_request' : null} fulfilment={item.fulfilment} />
      )}
      amount={item?.amount ?? null}
      hint={returned ? 'Fix what was returned, then resubmit.' : 'Nothing is sent until you submit.'}
      actions={footer}
      onClose={onClose}
    >
      {error && <div className="program-banner error" role="alert">{error}</div>}
      {!item && !error && <p className="ld-muted" aria-busy="true">Loading…</p>}

      {item && (
        <>
          <dl className="ld-fields">
            <div><dt>Encoded by</dt><dd>{item.submitted_by?.name ?? '—'}</dd></div>
            <div><dt>Submitted</dt><dd>{formatDateTime(item.submitted_at)}</dd></div>
            {item.fields.map((field) => (
              <div key={field.label}><dt>{field.label}</dt><dd>{fieldValue(field)}</dd></div>
            ))}
          </dl>
          {returned
            ? <DetailNote label="Returned" tone="risk">{returned}</DetailNote>
            : item.next_step && <p className="ld-waiting"><span aria-hidden="true">◷</span> {item.next_step}</p>}

          {Array.isArray(item.pr_items) && (
            <PurchaseRequestItemsTable items={item.pr_items} total={item.pr_total} showStatus={['endorsed', 'fulfilled', 'cancelled'].includes(item.workflow_status)} />
          )}
          {item.type === 'purchase_request' && (
            <div className="ld-panel-actions pr-download">
              <PurchaseRequestDownload requestId={item.id} reference={item.reference} />
            </div>
          )}

          <h3 className="ld-panel-sub">Documents</h3>
          <DocumentList key={`${item.key}#${refreshKey}`} type={item.type} id={item.id} />
        </>
      )}
    </DetailModal>
  );
}
