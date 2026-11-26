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
import StagePill from '../../components/assistant/StagePill';
import { useRecordActions } from '../../components/assistant/useRecordActions';
import BalancingCheck from '../../components/assistant/BalancingCheck';
import { peso } from '../../components/money';
import { formatDate } from '../../lib/datetime';

const RECENT_LIMIT = 5;

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
            detail={data?.project.project_leader ? `Project Leader: ${data.project.project_leader}` : null}
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
              <div className="pl-kpi-grid ra-kpi-grid">
                <ReturnedKpi value={data.kpis.returned} to={`/my-submissions${q}${q ? '&' : '?'}tab=returned`} />
                <Kpi to={`/my-submissions${q}`} label="Waiting for Project Leader" value={data.kpis.waiting} />
                <Kpi to={`/expenses${q}`} label="Missing receipts" value={data.kpis.missing_receipts} />
              </div>

              <section className="program-card">
                <div className="program-card-head">
                  <div>
                    <div className="program-card-title">My submissions</div>
                  </div>
                  <Link className="program-card-link" to={`/my-submissions${q}`}>View all</Link>
                </div>
                {data.kpis.drafts > 0 && (
                  <p className="ra-drafts-note">
                    {data.kpis.drafts} {data.kpis.drafts === 1 ? 'draft' : 'drafts'} not yet submitted ·{' '}
                    <Link to={`/my-submissions${q}${q ? '&' : '?'}tab=drafts`}>Continue</Link>
                  </p>
                )}
                {data.submissions.length === 0 ? (
                  <p className="ld-empty">You have not encoded anything yet. <Link to={`/expenses${q}`}>Record an expense</Link> to start.</p>
                ) : (
                  <RecentList items={data.submissions.slice(0, RECENT_LIMIT)} onOpen={(item) => update({ open: item.key })} />
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

function Kpi({ label, value, to }) {
  return (
    <Link to={to} className="program-card pl-kpi ra-kpi">
      <div className="pl-kpi-head"><span className="pl-kpi-label">{label}</span></div>
      <div className="pl-kpi-value"><AnimatedNumber value={value} /></div>
    </Link>
  );
}

/** The hero card: the whole card opens My submissions → Returned, at any count. */
function ReturnedKpi({ value, to }) {
  return (
    <Link to={to} className="program-card pl-kpi ra-kpi pl-kpi-hero">
      <div className="pl-kpi-head"><span className="pl-kpi-label">Returned to you</span></div>
      <div className="pl-kpi-value"><AnimatedNumber value={value} /></div>
      {value > 0 && <div className="pl-kpi-sub">The Project Leader asked for corrections</div>}
    </Link>
  );
}

/** The newest submissions (the server sends them newest first). */
function RecentList({ items, onOpen }) {
  return (
    <ul className="ra-recent" aria-label="My latest submissions">
      {items.map((item) => (
        <li key={item.key} className="ra-recent-row">
          <div className="ra-recent-main">
            <button type="button" className="ld-link ra-recent-title" onClick={() => onOpen(item)}>{item.title || item.reference}</button>
            <div className="ra-recent-meta">{item.line_item || item.type_label}</div>
          </div>
          <StagePill status={item.workflow_status} type={item.type === 'purchase_request' ? 'purchase_request' : null} fulfilment={item.fulfilment} />
          <span className="ra-recent-date">{formatDate(item.updated_at ?? item.submitted_at)}</span>
          <span className="ra-recent-amount">{item.amount != null ? peso(item.amount) : ''}</span>
        </li>
      ))}
    </ul>
  );
}
