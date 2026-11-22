import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ENDPOINTS } from '../../api/endpoints';
import { useApiData } from '../../lib/useApiData';
import { useAssignedProject } from '../../lib/useAssignedProject';
import { notAssignedText } from '../../lib/myProjects';
import { useUrlQuery } from '../../lib/useUrlQuery';
import { parseOpen } from '../../lib/assistantStatus';
import LoadState from '../../components/LoadState';
import ProjectLine from '../../components/ProjectLine';
import AnimatedNumber from '../../components/program/AnimatedNumber';
import AssistantRecordPanel from '../../components/assistant/AssistantRecordPanel';
import SubmissionTable from '../../components/assistant/SubmissionTable';
import { useRecordActions } from '../../components/assistant/useRecordActions';
import BalancingCheck from '../../components/assistant/BalancingCheck';

/**
 * Research Assistant Overview: what they encoded and who has it now, and
 * the balancing query for one line item. It has no create buttons: each
 * page (Expenses and receipts, Purchase requests, Payroll) has its own, and
 * documents are attached inside each record. The "Missing receipts" KPI
 * opens Expenses and receipts. Counts come from the server
 * (assistant-overview); the "Returned to you" KPI and the sidebar badge use
 * the same query. "Switch project" sits on the project line (ProjectLine).
 */
export default function ResearchAssistantDashboard() {
  const picker = useAssignedProject({ role: 'research-assistant' });
  const overview = useApiData(picker.projectId ? ENDPOINTS.assistantOverview(picker.projectId) : null);
  const { get, update } = useUrlQuery();
  const open = parseOpen(get('open'));
  const [refreshKey, setRefreshKey] = useState(0);
  const reload = () => { overview.retry(); setRefreshKey((value) => value + 1); };
  const actions = useRecordActions({ projectId: picker.projectId, onChanged: reload });

  const data = overview.data;
  const q = picker.projectId ? `?project=${picker.projectId}` : '';

  return (
    <div className="ld-page">
      <div className="pl-head">
        <div>
          <h1 className="program-h1">Encoding and payroll</h1>
          <ProjectLine
            picker={picker}
            project={data?.project ?? picker.project}
            detail={data?.project.project_leader
              ? `Project Leader: ${data.project.project_leader}`
              : (picker.project ? null : 'Expenses, purchase requests and payroll you encode')}
          />
        </div>
      </div>

      {actions.feedback && (
        <div className={`program-banner ${actions.feedback.tone}`} role="status" aria-live="polite">{actions.feedback.text}</div>
      )}

      <LoadState state={picker.state} emptyText={notAssignedText(picker.role)} isEmpty={picker.state.status === 'ready' && !picker.project}>
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
                  <p className="ld-empty">You have not encoded anything yet. <Link to={`/expenses${q}`}>Record an expense</Link> to start.</p>
                ) : (
                  <SubmissionTable items={data.submissions} caption="My latest submissions" onOpen={(item) => update({ open: item.key })} />
                )}
              </section>

              <BalancingCheck projectId={picker.projectId} />
            </>
          )}
        </LoadState>
      </LoadState>

      {picker.projectId && (
        <AssistantRecordPanel projectId={picker.projectId} target={open} refreshKey={refreshKey} actions={actions} onClose={() => update({ open: '' })} />
      )}
      {actions.dialog}
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
