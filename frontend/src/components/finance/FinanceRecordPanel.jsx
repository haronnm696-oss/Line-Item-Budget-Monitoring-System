import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiGet } from '../../api/http';
import { ENDPOINTS } from '../../api/endpoints';
import { formatDate, formatDateTime } from '../../lib/datetime';
import DocumentList from '../leader/DocumentList';
import StagePill from '../assistant/StagePill';
import { peso } from '../money';

function fieldValue(field) {
  if (field.value == null || field.value === '') return '—';
  if (field.kind === 'money') return peso(field.value);
  if (field.kind === 'date') return formatDate(field.value);
  return field.value;
}

/**
 * Side panel for one expense or payroll batch (loaded fresh, any stage) with
 * its documents (preview and download). Verify / Reject appear only when the
 * server says this user may verify (can_verify); otherwise blocked_text says
 * why (paid in the batch, no eligible verifier, own record, ...).
 * `verifyHere={false}` (Payroll page): points to the queue instead.
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

  useEffect(() => {
    if (!target) return undefined;
    const onKey = (event) => { if (event.key === 'Escape') onClose(); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [target, onClose]);

  if (!target) return null;
  const item = result?.key === key ? result.item : null;
  const error = result?.key === key ? result.error : null;
  const busy = Boolean(item && actions.busyKey === item.key);

  return (
    <div className="ld-panel-overlay" role="presentation" onClick={onClose}>
      <aside className="ld-panel" role="dialog" aria-modal="true" aria-labelledby="fin-panel-title" onClick={(event) => event.stopPropagation()}>
        <div className="ld-panel-head">
          <div>
            <div className="ld-panel-eyebrow">{item ? `${item.type_label} · ${item.reference}` : 'Record'}</div>
            <h2 className="ld-panel-title" id="fin-panel-title">{item?.title ?? 'Loading…'}</h2>
          </div>
          <button type="button" className="program-icon-btn" onClick={onClose} aria-label="Close panel">×</button>
        </div>

        {error && <div className="program-banner error" role="alert">{error}</div>}
        {!item && !error && <p className="ld-muted" aria-busy="true">Loading…</p>}

        {item && (
          <>
            <div className="ld-panel-status">
              <StagePill status={item.workflow_status} />
              {item.amount != null && <b className="ld-amount">{peso(item.amount)}</b>}
            </div>
            {item.blocked_text && <p className="ld-waiting"><span aria-hidden="true">⊘</span> {item.blocked_text}</p>}
            {!item.blocked_text && item.next_step && item.workflow_status !== 'endorsed' && (
              <p className="ld-waiting"><span aria-hidden="true">◷</span> {item.next_step}</p>
            )}
            {item.return_reason && <p className="ld-reason">Reason: “{item.return_reason}”</p>}
            {item.approval_note && <p className="ld-muted">{item.approval_note}</p>}

            <dl className="ld-fields">
              <div><dt>{item.type === 'payroll' ? 'Prepared by' : 'Recorded by'}</dt><dd>{item.submitted_by?.name ?? '—'}</dd></div>
              <div><dt>Submitted</dt><dd>{formatDateTime(item.submitted_at)}</dd></div>
              {item.fields.map((field) => (
                <div key={field.label}><dt>{field.label}</dt><dd>{fieldValue(field)}</dd></div>
              ))}
            </dl>

            <h3 className="ld-panel-sub">Documents</h3>
            <DocumentList key={`${item.key}#${refreshKey}`} type={item.type} id={item.id} />

            {item.can_verify && !verifyHere && (
              <p className="ld-waiting"><span aria-hidden="true">◷</span> Waiting for you: verify it from the{' '}
                <Link to={`/verification-queue?project=${projectId}&open=${item.key}`}>Verification queue</Link>.
              </p>
            )}
            {((item.can_verify && verifyHere) || item.can_edit) && (
              <div className="ld-panel-actions ra-panel-actions">
                {item.can_verify && verifyHere && (
                  <>
                    <p className="ld-muted fin-verify-note">
                      {`Verifying adds ${peso(item.amount)} to Total spent${item.type === 'payroll' ? ', charged to each person’s PS line item' : ''}. Spent = verified expenses and verified payroll.`}
                    </p>
                    <button type="button" className="program-btn danger" disabled={busy} onClick={() => actions.reject(item)}>Reject</button>
                    <button type="button" className="program-btn primary" disabled={busy} onClick={() => actions.verify(item)}>{busy ? 'Saving…' : 'Verify'}</button>
                  </>
                )}
                {item.can_edit && (
                  <button type="button" className="program-btn primary" onClick={() => actions.edit(item)}>
                    {item.workflow_status === 'returned' ? 'Edit and resubmit' : 'Edit and submit'}
                  </button>
                )}
              </div>
            )}
          </>
        )}
      </aside>
    </div>
  );
}
