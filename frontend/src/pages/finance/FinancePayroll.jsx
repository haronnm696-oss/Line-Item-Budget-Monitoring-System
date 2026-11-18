import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ENDPOINTS } from '../../api/endpoints';
import { useApiData } from '../../lib/useApiData';
import { useAssignedProject } from '../../lib/useAssignedProject';
import { notAssignedText } from '../../lib/myProjects';
import { useUrlQuery } from '../../lib/useUrlQuery';
import { parseOpen } from '../../lib/assistantStatus';
import LoadState from '../../components/LoadState';
import ProjectSelect from '../../components/leader/ProjectSelect';
import StagePill from '../../components/assistant/StagePill';
import FinanceRecordPanel from '../../components/finance/FinanceRecordPanel';
import { useVerifyActions } from '../../components/finance/useVerifyActions';
import { peso } from '../../components/money';
import { PayRatesCard } from '../../components/payroll/PayRates';
import RatesLabelBanner from '../../components/payroll/RatesLabelBanner';

/**
 * Payroll (Finance/Admin): every monthly batch of the project with its
 * stage and next step; a batch opens in the side panel. Verifying happens
 * from the Verification queue (the panel's buttons are the same). The
 * "Pay rates" card sets each assigned person's monthly rate and allowance.
 */
export default function FinancePayroll() {
  const picker = useAssignedProject({ role: 'finance-admin' });
  const { get, update } = useUrlQuery();
  const open = parseOpen(get('open'));
  const [refreshKey, setRefreshKey] = useState(0);
  const list = useApiData(picker.projectId ? ENDPOINTS.payrollBatches(picker.projectId) : null);
  const actions = useVerifyActions({
    projectId: picker.projectId,
    onChanged: () => { list.retry(); setRefreshKey((value) => value + 1); },
  });
  const batches = Array.isArray(list.data?.batches) ? list.data.batches : [];
  const q = picker.projectId ? `?project=${picker.projectId}` : '';

  return (
    <div className="ld-page">
      <div className="pl-head">
        <div>
          <div className="program-eyebrow">Verify · Finance/Admin</div>
          <h1 className="program-h1">Payroll</h1>
          <p className="program-sub">
            {picker.project ? `${picker.project.title} · ${picker.project.code} · ` : ''}Monthly batches; payroll is not part of LIB spent
          </p>
        </div>
        <div className="pl-head-actions">
          <ProjectSelect picker={picker} id="fin-payroll-project" />
          {picker.projectId && <Link className="program-btn" to={`/verification-queue${q}${q ? '&' : '?'}type=payroll`}>Payroll to verify</Link>}
        </div>
      </div>

      <RatesLabelBanner label={list.data?.rates_label} />

      {actions.feedback && (
        <div className={`program-banner ${actions.feedback.tone}`} role="status" aria-live="polite">{actions.feedback.text}</div>
      )}

      <LoadState state={picker.state} emptyText={notAssignedText(picker.role)} isEmpty={picker.state.status === 'ready' && !picker.project}>
        <LoadState state={list} loadingText="Loading payroll…" emptyText="No payroll has been prepared for this project yet." isEmpty={batches.length === 0}>
          <section className="program-card ld-list-card">
            <div className="ra-table-wrap">
              <table className="program-table ld-table ra-table">
                <caption className="pa-sr-only">Payroll batches</caption>
                <thead>
                  <tr>
                    <th scope="col">Month</th>
                    <th scope="col">People and net pay</th>
                    <th scope="col" className="num">Gross</th>
                    <th scope="col">Status and next step</th>
                  </tr>
                </thead>
                <tbody>
                  {batches.map((batch) => (
                    <tr key={batch.key}>
                      <td data-label="Month">
                        <button type="button" className="ld-link" onClick={() => update({ open: batch.key })}>{batch.reference}</button>
                        <div className="ra-type">{batch.title}</div>
                      </td>
                      <td data-label="People">{batch.details}</td>
                      <td data-label="Gross" className="num ld-amount">{peso(batch.amount)}</td>
                      <td data-label="Status">
                        <StagePill status={batch.workflow_status} />
                        {batch.blocked_text
                          ? <div className="ra-next fin-blocked">⊘ {batch.blocked_text}</div>
                          : <div className="ra-next">{batch.can_verify ? 'Waiting for you to verify' : batch.next_step}</div>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        </LoadState>

        {picker.projectId && <PayRatesCard key={picker.projectId} projectId={picker.projectId} editable />}

        {picker.projectId && (
          <FinanceRecordPanel projectId={picker.projectId} target={open} refreshKey={refreshKey} actions={actions} verifyHere={false} onClose={() => update({ open: '' })} />
        )}
      </LoadState>

      {actions.dialog}
    </div>
  );
}
