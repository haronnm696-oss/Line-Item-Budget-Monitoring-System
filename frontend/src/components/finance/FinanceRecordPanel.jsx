import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiGet } from '../../api/http';
import { ENDPOINTS } from '../../api/endpoints';
import { formatDate, formatDateTime } from '../../lib/datetime';
import { waitedText } from '../../lib/waited';
import DocumentList from '../leader/DocumentList';
import StagePill from '../assistant/StagePill';
import DetailModal, { DetailNote } from '../DetailModal';
import { peso } from '../money';

const TYPE_ICON = { expense: '▤', payroll: '₱' };

function fieldValue(field) {
  if (field.value == null || field.value === '') return '—';
  if (field.kind === 'money') return peso(field.value);
  if (field.kind === 'date') return formatDate(field.value);
  return field.value;
}

/**
 * Detail modal for one expense or payroll batch (loaded fresh, any stage)
 * with its documents (preview and download). Verify / Reject appear only
 * when the server says this user may verify (can_verify); otherwise
 * blocked_text says why (paid in the batch, no eligible verifier, own
 * record, ...). `verifyHere={false}` (Payroll page): points to the queue instead.
 */
export default function FinanceRecordPanel({ projectId, target, refreshKey, actions, onClose, verifyHere = true }) {
  const key = target ? `${target.type}-${target.id}#${refreshKey}` : '';
  const [result, setResult] = useState(null);

  useEffect(() => {
    if (!key) return undefined;
    let alive = true;
    apiGet(ENDPOINTS.verificationItem(projectId, target.type, target.id))
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

  const footer = item && ((item.can_verify && verifyHere) || item.can_edit) ? (
    <>
      {item.can_verify && verifyHere && (
        <>
          <button type="button" className="program-btn danger" disabled={busy} onClick={() => actions.reject(item)}>Reject</button>
          <button type="button" className="program-btn primary" disabled={busy} onClick={() => actions.verify(item)}>{busy ? 'Saving…' : 'Verify'}</button>
        </>
      )}
      {item.can_edit && (
        <button type="button" className="program-btn primary" onClick={() => actions.edit(item)}>
          {item.workflow_status === 'returned' ? 'Edit and resubmit' : 'Edit and submit'}
        </button>
      )}
    </>
  ) : null;
  const verifyNote = item?.can_verify && verifyHere
    ? `Verifying adds ${peso(item.amount)} to Total spent${item.type === 'payroll' ? ', charged to each person’s PS line item' : ''}. Spent = verified expenses and verified payroll.`
    : (item?.workflow_status === 'returned' ? 'Fix what was returned, then resubmit.' : 'Nothing is sent until you submit.');

  return (
    <DetailModal
      titleId="fin-panel-title"
      title={item?.title ?? 'Loading…'}
      eyebrow={item ? `${item.type_label} · ${item.reference}` : 'Record'}
      icon={TYPE_ICON[item?.type ?? target.type] ?? '▤'}
      chips={item && (
        <>
          <StagePill status={item.workflow_status} />
          {item.can_verify && <span className="dm-wait">{waitedText(item.submitted_at)}</span>}
        </>
      )}
      amount={item?.amount ?? null}
      hint={verifyNote}
      actions={footer}
      onClose={onClose}
    >
      {error && <div className="program-banner error" role="alert">{error}</div>}
      {!item && !error && <p className="ld-muted" aria-busy="true">Loading…</p>}

      {item && (
        <>
          <dl className="ld-fields">
            <div><dt>{item.type === 'payroll' ? 'Prepared by' : 'Recorded by'}</dt><dd>{item.submitted_by?.name ?? '—'}</dd></div>
            <div><dt>Submitted</dt><dd>{formatDateTime(item.submitted_at)}</dd></div>
            {item.fields.map((field) => (
              <div key={field.label}><dt>{field.label}</dt><dd>{fieldValue(field)}</dd></div>
            ))}
          </dl>
          {item.return_reason && (
            <DetailNote label="Returned" tone="risk"><span aria-hidden="true">↩</span> Reason: “{item.return_reason}”</DetailNote>
          )}
          {item.approval_note && <DetailNote>{item.approval_note}</DetailNote>}
          {item.blocked_text && <p className="ld-waiting"><span aria-hidden="true">⊘</span> {item.blocked_text}</p>}
          {!item.blocked_text && item.next_step && item.workflow_status !== 'endorsed' && (
            <p className="ld-waiting"><span aria-hidden="true">◷</span> {item.next_step}</p>
          )}

          <h3 className="ld-panel-sub">Documents</h3>
          <DocumentList key={`${item.key}#${refreshKey}`} type={item.type} id={item.id} />

          {item.can_verify && !verifyHere && (
            <p className="ld-waiting"><span aria-hidden="true">◷</span> Waiting for you: verify it from the{' '}
              <Link to={`/verification-queue?project=${projectId}&open=${item.key}`}>Verification queue</Link>.
            </p>
          )}
        </>
      )}
    </DetailModal>
  );
}
