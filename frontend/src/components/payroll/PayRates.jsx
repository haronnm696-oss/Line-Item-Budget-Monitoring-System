import { useState } from 'react';
import { ENDPOINTS } from '../../api/endpoints';
import { apiWrite } from '../../api/http';
import { errorText, fieldErrors } from '../../api/leader';
import { useApiData } from '../../lib/useApiData';
import { PAY_RATE_NOTE, rateInput, rateText, validatePayRate } from '../../lib/payRate';
import LoadState from '../LoadState';
import { peso } from '../money';

/**
 * Monthly rate and allowance fields for one assignment, saved with
 * PATCH /api/projects/{id}/pay-rates/{user} (one audit entry per change).
 * Empty monthly rate = "Not set".
 */
export function PayRateForm({ projectId, member, idBase, onSaved, onCancel }) {
  const [monthly, setMonthly] = useState(rateInput(member.monthly_rate));
  const [allowance, setAllowance] = useState(rateInput(member.monthly_rate === null ? null : member.allowance));
  const [errors, setErrors] = useState({});
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  async function save(event) {
    event.preventDefault();
    const checked = validatePayRate(monthly, allowance);
    setErrors(checked.errors);
    setError('');
    if (Object.keys(checked.errors).length) return;
    setBusy(true);
    try {
      const data = await apiWrite('patch', ENDPOINTS.payRate(projectId, member.user_id), checked.payload);
      onSaved(data?.member ?? null, `Pay rate for ${member.name} saved.`);
    } catch (failure) {
      const fields = fieldErrors(failure);
      setErrors(fields);
      if (!Object.keys(fields).length) setError(errorText(failure, 'Could not save. Please try again.'));
    } finally {
      setBusy(false);
    }
  }

  const field = (key, label, value, setValue) => (
    <div className="program-field">
      <label htmlFor={`${idBase}-${key}`}>{label}</label>
      <input
        id={`${idBase}-${key}`}
        className="program-input"
        type="text"
        inputMode="decimal"
        placeholder="Not set"
        value={value}
        disabled={busy}
        aria-invalid={Boolean(errors[key]) || undefined}
        aria-describedby={errors[key] ? `${idBase}-${key}-error` : `${idBase}-note`}
        onChange={(event) => { setValue(event.target.value); setErrors({ ...errors, [key]: undefined }); }}
      />
      {errors[key] && <p className="program-error" id={`${idBase}-${key}-error`}>{errors[key]}</p>}
    </div>
  );

  return (
    <form className="pr-form" onSubmit={save} noValidate>
      <div className="pu-asg-controls">
        {field('monthly_rate', 'Monthly rate (₱)', monthly, setMonthly)}
        {field('allowance', 'Allowance (₱)', allowance, setAllowance)}
      </div>
      <p className="pu-hint" id={`${idBase}-note`}>Leave the monthly rate empty for “Not set”. Zero or more, at most two decimals. {PAY_RATE_NOTE}</p>
      {error && <p className="program-error" role="alert">{error}</p>}
      <div className="program-reject-actions">
        {onCancel && <button type="button" className="program-btn" disabled={busy} onClick={onCancel}>Cancel</button>}
        <button type="submit" className="program-btn primary" disabled={busy}>{busy ? 'Saving…' : 'Save pay rate'}</button>
      </div>
    </form>
  );
}

/**
 * "Pay rates" card on the Payroll page: the project's assigned people with
 * their monthly rate and allowance. Finance/Admin edit; Research Assistants
 * read only. Researchers never see this card (the API refuses them too).
 */
export function PayRatesCard({ projectId, editable }) {
  const list = useApiData(projectId ? ENDPOINTS.payRates(projectId) : null);
  const [editing, setEditing] = useState(null);
  const [notice, setNotice] = useState('');
  const members = Array.isArray(list.data?.members) ? list.data.members : [];
  const unique = members.filter((member, index) => members.findIndex((m) => m.user_id === member.user_id) === index);

  return (
    <section className="program-card" aria-labelledby="pay-rates-title">
      <div className="program-card-head">
        <div>
          <div className="program-card-title" id="pay-rates-title">Pay rates</div>
          <div className="program-card-sub">{editable ? 'Monthly rate and allowance of each person assigned to this project' : 'Set by Finance/Admin or the Program Leader; read only'}</div>
        </div>
      </div>
      <p className="ld-hint">{PAY_RATE_NOTE}</p>
      <div aria-live="polite">{notice && <div className="program-banner ok">{notice}</div>}</div>
      <LoadState state={list} loadingText="Loading pay rates…" emptyText="No one is assigned to this project yet." isEmpty={unique.length === 0}>
        <div className="ra-table-wrap">
          <table className="program-table ld-table ra-table">
            <caption className="pa-sr-only">Pay rates</caption>
            <thead>
              <tr>
                <th scope="col">Name</th>
                <th scope="col" className="num">Monthly rate</th>
                <th scope="col" className="num">Allowance</th>
                {editable && <th scope="col"><span className="pa-sr-only">Actions</span></th>}
              </tr>
            </thead>
            <tbody>
              {unique.map((member) => (
                editing === member.user_id ? (
                  <tr key={member.user_id}>
                    <td colSpan={4}>
                      <div className="project-name">{member.name}</div>
                      <PayRateForm
                        projectId={projectId}
                        member={member}
                        idBase={`pay-rate-${member.user_id}`}
                        onCancel={() => setEditing(null)}
                        onSaved={(_, text) => { setEditing(null); setNotice(text); list.retry(); }}
                      />
                    </td>
                  </tr>
                ) : (
                  <tr key={member.user_id}>
                    <td data-label="Name">
                      <div className="project-name">{member.name ?? '—'}</div>
                      <div className="project-code">{[member.role, member.is_active ? null : 'Inactive'].filter(Boolean).join(' · ')}</div>
                    </td>
                    <td data-label="Monthly rate" className="num">{rateText(member.monthly_rate, peso)}</td>
                    <td data-label="Allowance" className="num">{member.monthly_rate === null ? 'Not set' : rateText(member.allowance, peso)}</td>
                    {editable && (
                      <td>
                        <button type="button" className="program-btn sm" onClick={() => { setNotice(''); setEditing(member.user_id); }}>
                          Edit<span className="pa-sr-only"> pay rate of {member.name}</span>
                        </button>
                      </td>
                    )}
                  </tr>
                )
              ))}
            </tbody>
          </table>
        </div>
      </LoadState>
    </section>
  );
}
