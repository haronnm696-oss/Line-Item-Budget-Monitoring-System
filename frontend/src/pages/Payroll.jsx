import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import Chip from '../components/Chip';
import LoadState from '../components/LoadState';
import ProjectPicker from '../components/ProjectPicker';
import { peso } from '../components/money';
import { apiWrite } from '../api/http';
import { ENDPOINTS } from '../api/endpoints';
import { useAuth } from '../context/AuthContext';
import { SAVE_ERROR } from '../lib/apiResult';
import { todayInManila } from '../lib/datetime';
import { useApiData } from '../lib/useApiData';
import { useAssignedProject } from '../lib/useAssignedProject';

const STAGE_TONE = { draft: 'warn', submitted: 'warn', endorsed: 'brand', returned: 'over', verified: 'ok' };
const MONTH_RE = /^\d{4}-(0[1-9]|1[0-2])$/;

/**
 * Monthly payroll of the chosen project. Rows (one per person) are created
 * and computed from the pay rates on the project assignments; the whole
 * month is then submitted, approved by the Project Leader and verified or
 * rejected by Finance/Admin as one batch. Data comes only from the API.
 */
export default function Payroll() {
  const { user } = useAuth();
  const picker = useAssignedProject();
  const [searchParams, setSearchParams] = useSearchParams();
  const requestedMonth = searchParams.get('month');
  const month = requestedMonth && MONTH_RE.test(requestedMonth) ? requestedMonth : todayInManila().slice(0, 7);

  const payroll = useApiData(picker.projectId ? `${ENDPOINTS.payroll(picker.projectId)}?month=${month}` : null);
  const [busy, setBusy] = useState(null); // 'create' | 'compute' | 'submit' | 'verify' | 'reject'
  const [notice, setNotice] = useState(null);
  const [lineItemId, setLineItemId] = useState('');
  const [rejectReason, setRejectReason] = useState(null); // null = not rejecting

  const run = payroll.data;
  const batch = run?.batch ?? null;
  const rows = Array.isArray(run?.rows) ? run.rows : [];
  const breakdown = Array.isArray(run?.deductionBreakdown) ? run.deductionBreakdown : [];
  const psItems = Array.isArray(run?.ps_line_items) ? run.ps_line_items : [];
  const unsetRules = Array.isArray(run?.unset_rules) ? run.unset_rules : [];
  const canPrepare = user.role === 'RA' || user.role === 'FIN';
  const canVerify = user.role === 'FIN';
  const editable = batch ? batch.editable : true;
  const chosenLineItem = lineItemId || (psItems[0] ? String(psItems[0].id) : '');
  const label = run?.month_label ?? month;

  function chooseMonth(value) {
    if (!MONTH_RE.test(value)) return;
    const next = new URLSearchParams(window.location.search);
    next.set('month', value);
    setSearchParams(next, { replace: true });
    setNotice(null);
    setRejectReason(null);
  }

  async function act(key, send, okText) {
    setBusy(key);
    setNotice(null);
    try {
      const data = await send();
      setNotice({ tone: 'ok', text: data?.message || okText });
      setRejectReason(null);
      payroll.retry();
    } catch (error) {
      setNotice({ tone: 'over', text: error.message || SAVE_ERROR });
    } finally {
      setBusy(null);
    }
  }

  const create = () => act('create', () => apiWrite('post', ENDPOINTS.payroll(picker.projectId), { month, lib_category_id: Number(chosenLineItem) }), 'Payroll created.');
  const compute = () => act('compute', () => apiWrite('post', ENDPOINTS.computePayroll(picker.projectId), { month }), 'Payroll computed.');
  const step = (action, body) => act(action, () => apiWrite('post', ENDPOINTS.payrollBatchStep(batch.id, action), body), 'Saved.');
  const spinner = (key) => busy === key && <span className="spin" />;

  return (
    <>
      <div className="page-head">
        <div>
          <h2>Payroll</h2>
          <p>{picker.project ? `${picker.project.title} · ` : ''}Monthly PS computation, approval and verification</p>
        </div>
        <ProjectPicker picker={picker} id="payroll-project" />
      </div>

      <LoadState state={picker.state} loadingText="Loading your projects…" isEmpty={picker.projects.length === 0} emptyText="You are not assigned to any project yet.">
        <div className="payroll-actions" style={{ marginBottom: 12, alignItems: 'flex-end' }}>
          <div className="field" style={{ marginBottom: 0, width: 'min(220px, 100%)' }}>
            <label htmlFor="payroll-month">Month</label>
            <input id="payroll-month" type="month" value={month} onChange={(event) => chooseMonth(event.target.value)} />
          </div>
        </div>

        <LoadState state={payroll} loadingText="Loading payroll…">
          {notice && <div className={`banner ${notice.tone}`} role={notice.tone === 'over' ? 'alert' : 'status'}>{notice.text}</div>}

          {unsetRules.length > 0 && (
            <div className="banner warn" role="status">
              Deduction rates are not set yet for: {unsetRules.join(', ')}. These deduct ₱0.00 until the rates are set in the payroll configuration.
            </div>
          )}

          {batch && rows.length > 0 && (
            <div className="panel" style={{ marginBottom: 14, padding: 16 }}>
              <div className="payroll-actions" style={{ justifyContent: 'space-between' }}>
                <div>
                  <b>{label} payroll</b>{' '}
                  <Chip tone={STAGE_TONE[batch.workflow_status] ?? 'neutral'}>{batch.workflow_label}</Chip>
                  <div style={{ color: 'var(--muted)', fontSize: 13, marginTop: 4 }}>
                    {rows.length} {rows.length === 1 ? 'person' : 'people'} · net {peso(run?.totals?.net)}
                    {batch.submitted_by && ` · submitted by ${batch.submitted_by.name}`}
                    {batch.pl_reviewed_by && batch.workflow_status !== 'returned' && ` · approved by ${batch.pl_reviewed_by.name}`}
                    {batch.verified_by && batch.workflow_status === 'verified' && ` · verified by ${batch.verified_by.name}`}
                  </div>
                  {batch.workflow_status === 'returned' && batch.return_reason && (
                    <div style={{ marginTop: 4 }}><small>Returned: {batch.return_reason}</small></div>
                  )}
                </div>

                <BatchActions
                  batch={batch}
                  label={label}
                  busy={busy}
                  canPrepare={canPrepare}
                  canVerify={canVerify}
                  rejectReason={rejectReason}
                  setRejectReason={setRejectReason}
                  compute={compute}
                  step={step}
                  spinner={spinner}
                />
              </div>
            </div>
          )}

          {rows.length === 0 && (
            <div className="panel" style={{ marginBottom: 14, padding: 16 }}>
              <p style={{ marginTop: 0 }}>No payroll for {label} yet.</p>
              {canPrepare && run?.rated_members === 0 && (
                <p style={{ color: 'var(--muted)' }}>No one on this project has a monthly rate yet. Finance/Admin staff set pay rates first.</p>
              )}
              {canPrepare && run?.rated_members > 0 && psItems.length === 0 && (
                <p style={{ color: 'var(--muted)' }}>This project has no Personal Services (PS) line item to charge payroll to.</p>
              )}
              {canPrepare && editable && run?.rated_members > 0 && psItems.length > 0 && (
                <div className="payroll-actions" style={{ alignItems: 'flex-end', gap: 12 }}>
                  <div className="field" style={{ marginBottom: 0, width: 'min(360px, 100%)' }}>
                    <label htmlFor="payroll-line-item">PS line item</label>
                    <select id="payroll-line-item" value={chosenLineItem} onChange={(event) => setLineItemId(event.target.value)}>
                      {psItems.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                    </select>
                  </div>
                  <button className="btn primary" onClick={create} disabled={busy !== null}>
                    {spinner('create')}Create payroll for {label}
                  </button>
                </div>
              )}
            </div>
          )}

          {rows.length > 0 && (
            <div className="panel wide-scroll" style={{ marginBottom: 14 }}>
              <table>
                <thead>
                  <tr>
                    <th>Personnel</th><th>Line item</th><th className="r">Monthly rate</th><th className="r">Allowance</th>
                    <th className="r">Gross pay</th><th className="r">Deductions</th><th className="r">Net pay</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.id}>
                      <td><b>{row.name}</b>{row.position && <><br /><small style={{ color: 'var(--muted)' }}>{row.position}</small></>}</td>
                      <td>{row.category}</td>
                      <td className="r num">{peso(row.basic)}</td>
                      <td className="r num">{peso(row.allowance)}</td>
                      <td className="r num">{peso(row.gross)}</td>
                      <td className="r num">{peso(row.deductions)}</td>
                      <td className="r num mono-b">{peso(row.net)}</td>
                    </tr>
                  ))}
                </tbody>
                <tfoot>
                  <tr>
                    <th colSpan={4}>Total</th>
                    <th className="r num">{peso(run?.totals?.gross)}</th>
                    <th className="r num">{peso(run?.totals?.deductions)}</th>
                    <th className="r num">{peso(run?.totals?.net)}</th>
                  </tr>
                </tfoot>
              </table>
            </div>
          )}

          {breakdown.length > 0 && (
            <div className="panel">
              <div className="panel-head"><h3>Deductions · {label}</h3></div>
              <div className="wide-scroll">
                <table>
                  <thead><tr><th>Type</th><th className="r">Amount</th></tr></thead>
                  <tbody>
                    {breakdown.map((d) => (
                      <tr key={d.type}><td>{d.type}</td><td className="r num">{peso(d.amount)}</td></tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </LoadState>
      </LoadState>
    </>
  );
}

/** Month-level actions: recompute and submit (preparers), verify / reject with a reason (Finance/Admin). */
function BatchActions({ batch, label, busy, canPrepare, canVerify, rejectReason, setRejectReason, compute, step, spinner }) {
  if (canPrepare && batch.editable) {
    return (
      <div className="payroll-actions">
        <button className="btn" onClick={compute} disabled={busy !== null}>{spinner('compute')}Recompute</button>
        <button className="btn primary" onClick={() => step('submit')} disabled={busy !== null}>{spinner('submit')}Submit {label} payroll</button>
      </div>
    );
  }

  if (canVerify && batch.workflow_status === 'endorsed') {
    if (rejectReason !== null) {
      return (
        <div className="field" style={{ marginBottom: 0, width: 'min(320px, 100%)' }}>
          <label htmlFor="payroll-reject-reason">Reason for rejecting</label>
          <textarea id="payroll-reject-reason" rows={2} value={rejectReason} onChange={(event) => setRejectReason(event.target.value)} />
          <div className="payroll-actions" style={{ marginTop: 6 }}>
            <button className="btn sm" onClick={() => step('reject', { reason: rejectReason })} disabled={busy !== null || !rejectReason.trim()}>
              {spinner('reject')}Reject
            </button>
            <button className="btn sm" onClick={() => setRejectReason(null)} disabled={busy !== null}>Cancel</button>
          </div>
        </div>
      );
    }
    return (
      <div className="payroll-actions">
        <button className="btn primary" onClick={() => step('verify')} disabled={busy !== null}>{spinner('verify')}Verify</button>
        <button className="btn" onClick={() => setRejectReason('')} disabled={busy !== null}>Reject</button>
      </div>
    );
  }

  if (batch.workflow_status === 'submitted') return <small style={{ color: 'var(--muted)' }}>Waiting for the Project Leader</small>;
  if (batch.workflow_status === 'endorsed') return <small style={{ color: 'var(--muted)' }}>Waiting for Finance/Admin</small>;
  return null;
}
