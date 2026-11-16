import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { ENDPOINTS } from '../../api/endpoints';
import { useApiData } from '../../lib/useApiData';
import { useAssignedProject } from '../../lib/useAssignedProject';
import { useUrlQuery } from '../../lib/useUrlQuery';
import { parseOpen } from '../../lib/assistantStatus';
import { formatInManila } from '../../lib/datetime';
import LoadState from '../../components/LoadState';
import AnimatedNumber from '../../components/program/AnimatedNumber';
import ProjectSelect from '../../components/leader/ProjectSelect';
import RecordExpenseDialog from '../../components/leader/RecordExpenseDialog';
import AssistantRecordPanel from '../../components/assistant/AssistantRecordPanel';
import PurchaseRequestDialog from '../../components/assistant/PurchaseRequestDialog';
import SubmissionTable from '../../components/assistant/SubmissionTable';
import UploadDocumentDialog from '../../components/assistant/UploadDocumentDialog';
import { useRecordActions } from '../../components/assistant/useRecordActions';
import BalancingCheck from '../../components/assistant/BalancingCheck';
import { peso } from '../../components/money';

function greeting() {
  const hour = Number(formatInManila(new Date(), { hour: 'numeric', hourCycle: 'h23' }, '12'));
  if (hour < 12) return 'Good morning';
  if (hour < 18) return 'Good afternoon';
  return 'Good evening';
}

/**
 * Research Assistant Overview: what they encoded and who has it now, the
 * balancing query for one line item and the project's missing receipts.
 * Counts come from the server (assistant-overview); the "Returned to you"
 * KPI and the sidebar badge use the same query.
 */
export default function ResearchAssistantDashboard() {
  const { user } = useAuth();
  const picker = useAssignedProject({ role: 'research-assistant' });
  const overview = useApiData(picker.projectId ? ENDPOINTS.assistantOverview(picker.projectId) : null);
  const { get, update } = useUrlQuery();
  const open = parseOpen(get('open'));
  const [refreshKey, setRefreshKey] = useState(0);
  const [dialog, setDialog] = useState(null); // 'expense' | 'purchase' | 'upload'
  const reload = () => { overview.retry(); setRefreshKey((value) => value + 1); };
  const actions = useRecordActions({ projectId: picker.projectId, onChanged: reload });

  const data = overview.data;
  const firstName = (user?.name ?? 'there').replace(/^(Dr|Engr|Prof)\.?\s+/i, '').split(' ')[0];
  const q = picker.projectId ? `?project=${picker.projectId}` : '';
  const done = (text) => { setDialog(null); actions.setFeedback({ tone: 'ok', text }); reload(); };

  return (
    <div className="ld-page">
      <div className="pl-head">
        <div>
          <div className="pl-greeting">{greeting()}, {firstName}</div>
          <h1 className="program-h1">Encoding and payroll</h1>
          <p className="program-sub">
            {data
              ? [data.project.title, data.project.code, data.project.project_leader ? `Project Leader: ${data.project.project_leader}` : null].filter(Boolean).join(' · ')
              : picker.project ? `${picker.project.title} · ${picker.project.code}` : 'Expenses, purchase requests and payroll you encode'}
          </p>
        </div>
        {picker.projectId && (
          <div className="pl-head-actions">
            <ProjectSelect picker={picker} id="ra-overview-project" />
            <button type="button" className="program-btn" onClick={() => { actions.setFeedback(null); setDialog('upload'); }} disabled={!data}>Upload document</button>
            <button type="button" className="program-btn" onClick={() => { actions.setFeedback(null); setDialog('purchase'); }}>Purchase request</button>
            <Link className="program-btn" to={`/payroll${q}`}>Upload payroll</Link>
            <button type="button" className="program-btn primary" onClick={() => { actions.setFeedback(null); setDialog('expense'); }}>Record expense</button>
          </div>
        )}
      </div>

      {actions.feedback && (
        <div className={`program-banner ${actions.feedback.tone}`} role="status" aria-live="polite">{actions.feedback.text}</div>
      )}

      <LoadState state={picker.state} emptyText="You are not assigned to a project as a Research Assistant yet." isEmpty={picker.state.status === 'ready' && !picker.project}>
        <LoadState state={overview} loadingText="Loading your submissions…">
          {data && (
            <>
              <div className="pl-kpi-grid">
                <Kpi hero to={`/my-submissions${q}${q ? '&' : '?'}tab=drafts`} label="Drafts" value={data.kpis.drafts} sub="Not yet submitted" />
                <Kpi to={`/my-submissions${q}`} label="Waiting for Project Leader" value={data.kpis.waiting} sub="Submitted, not yet approved" />
                <Kpi alert={data.kpis.returned > 0} to={`/my-submissions${q}${q ? '&' : '?'}tab=returned`} label="Returned to you" value={data.kpis.returned}
                  sub={data.kpis.returned > 0 ? '⚠ Needs your correction' : 'Nothing to correct'} />
                <Kpi to={`/expenses${q}`} label="Missing receipts" value={data.kpis.missing_receipts} sub="Verified expenses without a receipt" />
              </div>

              <section className="program-card">
                <div className="program-card-head">
                  <div>
                    <div className="program-card-title">My submissions</div>
                    <div className="program-card-sub">Every record you encoded, and who has it now</div>
                  </div>
                  <Link className="program-card-link" to={`/my-submissions${q}`}>View all</Link>
                </div>
                {data.submissions.length === 0 ? (
                  <p className="ld-empty">You have not encoded anything yet. Record an expense to start.</p>
                ) : (
                  <SubmissionTable items={data.submissions} caption="My latest submissions" onOpen={(item) => update({ open: item.key })} />
                )}
              </section>

              <div className="pl-row pl-row-main">
                <BalancingCheck projectId={picker.projectId} />
                <MissingReceipts items={data.missing_receipts} count={data.kpis.missing_receipts} projectQuery={q}
                  onUpload={(row) => actions.upload({
                    key: `expense-${row.id}`, type: 'expense', id: row.id, type_label: 'Expense',
                    reference: row.reference_no || `EXP-${row.id}`, title: row.description, amount: row.amount,
                  }, { types: ['receipt'], title: 'Upload missing receipt' })} />
              </div>
            </>
          )}
        </LoadState>
      </LoadState>

      {picker.projectId && (
        <AssistantRecordPanel projectId={picker.projectId} target={open} refreshKey={refreshKey} actions={actions} onClose={() => update({ open: '' })} />
      )}
      {actions.dialog}
      {dialog === 'expense' && <RecordExpenseDialog assistant projectId={picker.projectId} onClose={() => { setDialog(null); reload(); }} onDone={done} />}
      {dialog === 'purchase' && <PurchaseRequestDialog projectId={picker.projectId} onClose={() => { setDialog(null); reload(); }} onDone={done} />}
      {dialog === 'upload' && data && (
        <UploadDocumentDialog projectId={picker.projectId} missingReceipts={data.missing_receipts} onClose={() => setDialog(null)} onDone={done} />
      )}
    </div>
  );
}

function Kpi({ label, value, sub, to, hero = false, alert = false }) {
  return (
    <Link to={to} className={`program-card pl-kpi ra-kpi${hero ? ' pl-kpi-hero' : ''}${alert ? ' ra-kpi-alert' : ''}`}>
      <div className="pl-kpi-head"><span className="pl-kpi-label">{label}</span></div>
      <div className="pl-kpi-value"><AnimatedNumber value={value} /></div>
      <div className="pl-kpi-sub">{sub}</div>
    </Link>
  );
}

function MissingReceipts({ items, count, projectQuery, onUpload }) {
  return (
    <section className="program-card">
      <div className="program-card-head">
        <div>
          <div className="program-card-title">Missing receipts</div>
          <div className="program-card-sub">Upload the invoice or official receipt</div>
        </div>
        {count > items.length && <Link className="program-card-link" to={`/expenses${projectQuery}`}>View all {count}</Link>}
      </div>
      {items.length === 0 ? (
        <p className="ld-empty">✓ Every verified expense has a receipt.</p>
      ) : (
        <ul className="ld-mini-list">
          {items.map((row) => (
            <li key={row.id}>
              <div>
                <b>{row.reference_no || `EXP-${row.id}`} · {peso(row.amount)}</b>
                <span>{row.description}</span>
              </div>
              <button type="button" className="program-btn sm" onClick={() => onUpload(row)}>Upload</button>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
