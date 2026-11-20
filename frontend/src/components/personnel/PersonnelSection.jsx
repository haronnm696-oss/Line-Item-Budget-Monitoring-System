import { useState } from 'react';
import { ENDPOINTS } from '../../api/endpoints';
import { apiUrl } from '../../api/leader';
import client from '../../api/client';
import { performWrite } from '../../lib/apiResult';
import { useApiData } from '../../lib/useApiData';
import { formatDate } from '../../lib/datetime';
import { periodText, personnelForm, validatePersonnel } from '../../lib/personnel';
import { Dialog, Field, RemoveDialog, useSave } from '../program/ProjectSetup';
import MoneyInput from '../MoneyInput';
import { DATE_MAX, DATE_MIN } from '../../lib/dateRange';
import LoadState from '../LoadState';
import { peso } from '../money';

/**
 * The project's Personnel: the team appointed by Special Order, paid PS
 * honoraria through payroll (the one place pay is kept; payroll reads it).
 * The server decides what each role gets (GET /api/projects/:id/personnel):
 *   edit     Program Leader: add, edit, remove, Special Order file
 *   view     Project Leader, Finance/Admin: read-only
 *   limited  Research Assistant: name, position and period only
 * Researchers get 403 and this section renders nothing for them.
 * Bank accounts are only ever shown masked ("****9170").
 */
export default function PersonnelSection({ projectId }) {
  const list = useApiData(projectId ? ENDPOINTS.personnel(projectId) : null);
  const [dialog, setDialog] = useState(null); // { kind: 'form' | 'remove' | 'order', record? }
  const [notice, setNotice] = useState('');

  // Not allowed for this role: the section is not shown at all.
  if (list.status === 'error' && list.error?.status === 403) return null;

  const data = list.data;
  const access = data?.access;
  const records = Array.isArray(data?.personnel) ? data.personnel : [];
  const full = access === 'edit' || access === 'view';
  const editable = access === 'edit';

  function done(message) {
    setDialog(null);
    setNotice(message);
    list.retry();
  }

  return (
    <section className="program-card" aria-labelledby="personnel-title">
      <div className="program-card-head">
        <div>
          <div className="program-card-title" id="personnel-title">Personnel</div>
          <div className="program-card-sub">
            Project team appointed by Special Order, paid PS honoraria through payroll. Job Order and professional-services staff are paid through expenses.
            {access === 'view' && ' Read only; the Program Leader keeps this list.'}
            {access === 'limited' && ' Positions and periods only.'}
          </div>
        </div>
        {editable && (
          <button type="button" className="program-btn" onClick={() => { setNotice(''); setDialog({ kind: 'form' }); }}>Add personnel</button>
        )}
      </div>
      <div aria-live="polite">{notice && <div className="program-banner ok">{notice}</div>}</div>

      <LoadState state={list} loadingText="Loading personnel…" emptyText="No personnel yet." isEmpty={records.length === 0}>
        <div className="program-scroll">
          <table className="program-table pd-table">
            <caption className="pa-sr-only">Personnel appointed by Special Order</caption>
            <thead>
              <tr>
                <th scope="col">Name and position</th>
                <th scope="col">Effective</th>
                {full && <th scope="col">Special Order</th>}
                {full && <th scope="col">PS line item</th>}
                {full && <th scope="col" className="num">Monthly honorarium</th>}
                {full && <th scope="col">Bank account</th>}
                {editable && <th scope="col"><span className="pa-sr-only">Actions</span></th>}
              </tr>
            </thead>
            <tbody>
              {records.map((record) => (
                <tr key={record.id}>
                  <td>
                    <div className="project-name">{record.name ?? '—'}</div>
                    <div className="project-code">{record.position}</div>
                  </td>
                  <td>{periodText(record.effective_from, record.effective_to, formatDate)}</td>
                  {full && (
                    <td>
                      <div>{record.special_order_no || <span className="project-code">⚠ Not entered</span>}</div>
                      {record.special_order_document
                        ? <a className="project-code" href={apiUrl(ENDPOINTS.documentDownload(record.special_order_document.id))}>📄 {record.special_order_document.file_name}</a>
                        : <span className="project-code">No file</span>}
                    </td>
                  )}
                  {full && <td>{record.line_item ?? <span className="project-code">⚠ Not linked</span>}</td>}
                  {full && <td className="num">{peso(record.monthly_honorarium)}</td>}
                  {full && <td className="num">{record.bank_account_masked ?? '—'}</td>}
                  {editable && (
                    <td className="pd-actions">
                      <button type="button" className="program-btn sm" onClick={() => { setNotice(''); setDialog({ kind: 'form', record }); }}>
                        Edit<span className="pa-sr-only"> {record.name}</span>
                      </button>
                      <button type="button" className="program-btn sm" onClick={() => { setNotice(''); setDialog({ kind: 'order', record }); }}>
                        Special Order file<span className="pa-sr-only"> of {record.name}</span>
                      </button>
                      <button type="button" className="program-btn sm danger" onClick={() => { setNotice(''); setDialog({ kind: 'remove', record }); }}>
                        Remove<span className="pa-sr-only"> {record.name}</span>
                      </button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </LoadState>

      {dialog?.kind === 'form' && (
        <PersonnelDialog projectId={projectId} data={data} record={dialog.record ?? null} onClose={() => setDialog(null)} onSaved={done} />
      )}
      {dialog?.kind === 'order' && (
        <SpecialOrderDialog record={dialog.record} onClose={() => setDialog(null)} onSaved={done} />
      )}
      {dialog?.kind === 'remove' && (
        <RemoveDialog
          title={`Remove ${dialog.record.name} from personnel`}
          text={dialog.record.special_order_document
            ? 'Remove the uploaded Special Order file first.'
            : 'They stay assigned to the project; payroll no longer includes them. Verified payroll is not changed.'}
          url={ENDPOINTS.personnelRecord(dialog.record.id)}
          onClose={() => setDialog(null)}
          onSaved={done}
        />
      )}
    </section>
  );
}

/** Add (record null) or edit one personnel record. */
function PersonnelDialog({ projectId, data, record, onClose, onSaved }) {
  const creating = !record;
  const period = data?.period ?? {};
  const members = data?.members ?? [];
  const lineItems = data?.ps_line_items ?? [];
  const [form, setForm] = useState(personnelForm(record));
  const [local, setLocal] = useState({});
  const { busy, error, serverErrors, save } = useSave(onSaved);
  const errors = { ...serverErrors, ...local };
  const set = (key) => (event) => {
    const value = event.target.type === 'checkbox' ? event.target.checked : event.target.value;
    setForm({ ...form, [key]: value });
    setLocal({ ...local, [key]: undefined });
  };

  function submit(event) {
    event.preventDefault();
    const checked = validatePersonnel(form, period, { creating });
    setLocal(checked.errors);
    if (Object.keys(checked.errors).length) return;
    if (creating) save('post', ENDPOINTS.programPersonnel(projectId), checked.body, 'Personnel added.');
    else save('patch', ENDPOINTS.personnelRecord(record.id), checked.body, 'Personnel saved.');
  }

  const input = (key, label, extra = {}, type = 'text') => (
    <Field id={`pn-${key}`} label={label} error={errors[key]} {...extra}>
      {(a11y) => (type === 'money' ? (
        <MoneyInput {...a11y} value={form[key]} disabled={busy} onChange={set(key)} />
      ) : (
        <input
          {...a11y}
          className="program-input"
          type={type}
          value={form[key]}
          disabled={busy}
          onChange={set(key)}
          min={type === 'date' ? period.start ?? DATE_MIN : undefined}
          max={type === 'date' ? period.end ?? DATE_MAX : undefined}
          inputMode={key === 'bank_account' ? 'numeric' : undefined}
          autoComplete="off"
        />
      ))}
    </Field>
  );

  const periodHint = period.start && period.end ? `Project period: ${formatDate(period.start)} – ${formatDate(period.end)}` : undefined;

  return (
    <Dialog title={creating ? 'Add personnel' : `Edit ${record.name}`} labelId="pn-title" busy={busy} onClose={onClose}>
      <form className="pu-form" onSubmit={submit} noValidate>
        {error && <div className="program-banner error" role="alert">{error}</div>}
        <div className="program-form-grid">
          {creating && (
            <Field id="pn-user_id" label="Project member" error={errors.user_id} full>
              {(a11y) => (
                <select {...a11y} className="program-select" value={form.user_id} disabled={busy} onChange={set('user_id')}>
                  <option value="">Choose a member</option>
                  {members.map((member) => (
                    <option key={member.user_id} value={member.user_id}>{member.name}{member.role ? ` · ${member.role}` : ''}</option>
                  ))}
                </select>
              )}
            </Field>
          )}
          {input('position', 'Position (as written in Form B)', { full: true, hint: 'For example "Project Staff Level 3"' })}
          <Field id="pn-lib_category_id" label="PS line item" error={errors.lib_category_id} full>
            {(a11y) => (
              <select {...a11y} className="program-select" value={form.lib_category_id} disabled={busy} onChange={set('lib_category_id')}>
                <option value="">{lineItems.length ? 'Choose a PS line item' : 'Add a PS line item to the budget first'}</option>
                {lineItems.map((item) => <option key={item.id} value={item.id}>{item.code ? `${item.code} · ` : ''}{item.name}</option>)}
              </select>
            )}
          </Field>
          {input('special_order_no', 'Special Order number', { full: true, hint: 'For example "SO No. 1111-OP s. 2025"' })}
          {input('effective_from', 'Effective from', { hint: periodHint }, 'date')}
          {input('effective_to', 'Effective to', {}, 'date')}
          {input('monthly_honorarium', 'Monthly honorarium (₱)', { hint: 'Zero or more, at most two decimals, e.g. 7,500.00' }, 'money')}
          {input('bank_account', 'Bank account number (optional)', {
            hint: record?.bank_account_masked
              ? `Stored: ${record.bank_account_masked}. Leave blank to keep it.`
              : 'Stored encrypted; shown masked.',
          })}
          {record?.bank_account_masked && (
            <div className="program-field full">
              <label className="ps-check">
                <input type="checkbox" checked={form.remove_bank_account} disabled={busy || form.bank_account !== ''} onChange={set('remove_bank_account')} />
                Remove the stored bank account ({record.bank_account_masked})
              </label>
            </div>
          )}
        </div>
        <div className="program-modal-actions">
          <button type="button" className="program-btn" onClick={onClose} disabled={busy}>Cancel</button>
          <button type="submit" className="program-btn primary" disabled={busy}>{busy ? 'Saving…' : creating ? 'Add personnel' : 'Save'}</button>
        </div>
      </form>
    </Dialog>
  );
}

/** Upload or remove the record's Special Order (PDF, JPG or PNG, up to 5 MB). */
function SpecialOrderDialog({ record, onClose, onSaved }) {
  const [file, setFile] = useState(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const current = record.special_order_document;

  async function run(send, message) {
    setBusy(true);
    setError('');
    try {
      await performWrite(send);
      onSaved(message);
    } catch (failure) {
      const fields = failure?.fieldErrors ?? {};
      const first = Object.values(fields).flat()[0];
      setError(first || failure?.cause?.response?.data?.message || 'Could not save. Please try again.');
      setBusy(false);
    }
  }

  function upload(event) {
    event.preventDefault();
    if (!file) { setError('Choose a file.'); return; }
    const body = new FormData();
    body.append('file', file);
    run(() => client.post(ENDPOINTS.personnelSpecialOrder(record.id), body), 'Special Order uploaded.');
  }

  return (
    <Dialog title={`Special Order of ${record.name}`} labelId="pn-order-title" busy={busy} onClose={onClose}>
      <form className="pu-form" onSubmit={upload} noValidate>
        {error && <div className="program-banner error" role="alert">{error}</div>}
        {current ? (
          <>
            <p className="pu-confirm">Uploaded: <a href={apiUrl(ENDPOINTS.documentDownload(current.id))}>📄 {current.file_name}</a></p>
            <p className="pu-hint">To replace it, remove this file first, then upload the new one.</p>
            <div className="program-modal-actions">
              <button type="button" className="program-btn" onClick={onClose} disabled={busy}>Close</button>
              <button
                type="button"
                className="program-btn danger"
                disabled={busy}
                onClick={() => run(() => client.delete(ENDPOINTS.personnelSpecialOrder(record.id)), 'Special Order removed.')}
              >
                {busy ? 'Removing…' : 'Remove file'}
              </button>
            </div>
          </>
        ) : (
          <>
            <div className="program-field">
              <label htmlFor="pn-order-file">Special Order file (PDF, JPG or PNG, up to 5 MB)</label>
              <input id="pn-order-file" className="program-input" type="file" accept=".pdf,.jpg,.jpeg,.png" disabled={busy} onChange={(event) => { setFile(event.target.files?.[0] ?? null); setError(''); }} />
            </div>
            <div className="program-modal-actions">
              <button type="button" className="program-btn" onClick={onClose} disabled={busy}>Cancel</button>
              <button type="submit" className="program-btn primary" disabled={busy}>{busy ? 'Uploading…' : 'Upload'}</button>
            </div>
          </>
        )}
      </form>
    </Dialog>
  );
}
