import { useEffect, useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import { assignToProject, fetchProgramProject } from '../../api/program';
import { ENDPOINTS } from '../../api/endpoints';
import ProgramPill from '../../components/program/ProgramPill';
import { LineItemDialog, ProjectFormDialog, RemoveDialog, TrancheDialog } from '../../components/program/ProjectSetup';
import { formatPct, peso } from '../../components/money';
import { formatDate, formatDateTime } from '../../lib/datetime';
import FundingWarnings from '../../components/program/FundingWarnings';
import FormBTable from '../../components/projects/FormBTable';
import PersonnelSection from '../../components/personnel/PersonnelSection';
import ProjectDocumentsSection from '../../components/projects/ProjectDocumentsSection';
import PurchaseRequestsSection from '../../components/projects/PurchaseRequestsSection';
import { agreementLines } from '../../lib/projectAgreement';
import { formBBlocker } from '../../lib/formBPrint';
import { formatShare, releaseShare, releaseSummary, splitAmounts } from '../../lib/releasePlan';
import { apiWrite } from '../../api/http';
import { errorText } from '../../api/leader';
import { RowMenu } from '../../components/ActionMenu';
import ProgramAssignUserModal from '../../components/program/ProgramAssignUserModal';
import { canAssignTo } from '../../lib/assignForm';
import { activityTitle, menuOptions, trancheActions } from '../../lib/projectRowActions';
import Tip from '../../components/Tip';

const LIQUIDATION_TONE = { Approved: 'healthy', Pending: 'watch', Rejected: 'risk', 'Not submitted': 'watch' };

export default function ProgramProjectDetail() {
  const { id } = useParams();
  const location = useLocation();
  const backTo = location.state?.from?.startsWith('/program-projects') ? location.state.from : '/program-projects';
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  // Set-up dialogs: { kind: 'details' | 'line' | 'removeLine' | 'tranche' | 'removeTranche' | 'assign', item?, url? }
  const [dialog, setDialog] = useState(null);
  const [notice, setNotice] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [splitBusy, setSplitBusy] = useState(false);
  const [splitError, setSplitError] = useState('');
  const [renumberBusy, setRenumberBusy] = useState(false);
  const [renumberError, setRenumberError] = useState('');
  const [assignBusy, setAssignBusy] = useState(false);
  const [assignError, setAssignError] = useState('');
  // Bumped after "Add member" so Personnel reloads its member list.
  const [teamVersion, setTeamVersion] = useState(0);

  function saved(message) {
    setDialog(null);
    setNotice(message);
    setReloadKey((value) => value + 1);
  }

  useEffect(() => {
    let alive = true;
    setLoading(true);
    setError('');
    fetchProgramProject(id)
      .then((payload) => {
        if (alive) setData(payload);
      })
      .catch((requestError) => {
        if (!alive) return;
        setError(requestError?.response?.status === 404
          ? 'This project does not exist or was removed.'
          : requestError?.response?.data?.message || 'Unable to load this project.');
      })
      .finally(() => {
        if (alive) setLoading(false);
      });
    return () => { alive = false; };
  }, [id, reloadKey]);

  const backLink = (
    <Link className="program-card-link pd-back" to={backTo}>← Back to projects</Link>
  );

  if (loading && !data) {
    return (
      <>
        {backLink}
        <div className="program-card pl-loading">Loading project…</div>
      </>
    );
  }

  if (error || !data) {
    return (
      <>
        {backLink}
        <div className="program-banner error" role="alert">{error || 'Unable to load this project.'}</div>
      </>
    );
  }

  const project = data.project;
  const formB = data.form_b ?? null;
  const sections = formB?.sections ?? [];
  const tranches = data.tranches ?? [];
  const expenses = data.expenses ?? [];
  const team = data.team ?? [];
  const activity = data.activity ?? [];
  const setup = data.setup ?? null;
  const left = setup ? setup.total_budget - setup.allocated : 0;
  const formBReason = formBBlocker(project);
  // Releases are portions of the funder total (setup.funder_total).
  const funderTotal = Number(setup?.funder_total ?? 0) || 0;
  const releasePlan = releaseSummary(tranches, funderTotal);
  const splitPreview = splitAmounts(funderTotal);
  const assignable = canAssignTo(project);

  async function splitReleases() {
    if (splitBusy) return;
    setNotice('');
    setSplitError('');
    setSplitBusy(true);
    try {
      const result = await apiWrite('post', ENDPOINTS.programTranchesSplit(project.id));
      saved(result?.message ?? 'The 70% and 30% releases were added.');
    } catch (failure) {
      setSplitError(errorText(failure, 'Could not save. Please try again.'));
    } finally {
      setSplitBusy(false);
    }
  }

  /** Team > Add member: the same assignment call as Users and roles. */
  async function addMember(payload) {
    setAssignBusy(true);
    setAssignError('');
    try {
      await assignToProject(payload);
      setTeamVersion((value) => value + 1);
      saved('Member added to the project.');
    } catch (failure) {
      setAssignError(failure?.response?.data?.errors
        ? Object.values(failure.response.data.errors).flat()[0]
        : failure?.response?.data?.message || 'Unable to assign the user.');
    } finally {
      setAssignBusy(false);
    }
  }

  const openDialog = (next) => { setNotice(''); setDialog(next); };

  async function renumberReleases() {
    if (renumberBusy) return;
    setNotice('');
    setRenumberError('');
    setRenumberBusy(true);
    try {
      const result = await apiWrite('post', ENDPOINTS.programTranchesRenumber(project.id));
      saved(result?.message ?? 'Planned releases renumbered by planned date.');
    } catch (failure) {
      setRenumberError(errorText(failure, 'Could not renumber the releases. Please try again.'));
    } finally {
      setRenumberBusy(false);
    }
  }

  return (
    <>
      {backLink}

      <div className="program-page-head">
        <div className="pd-head">
          <div className="program-eyebrow">Project · {project.code}</div>
          <h1 className="program-h1">{project.title}</h1>
          <p className="program-sub">
            {project.funder}
            {project.funder_reference ? ` · ref. ${project.funder_reference}` : ''}
            {' · '}Project Leader: {project.project_leader || '—'}
            {' · '}{formatDate(project.start_date)} – {formatDate(project.end_date)}
          </p>
          {agreementLines(project.agreement, formatDate).length > 0 && (
            <ul className="pd-agreement" aria-label="Memorandum of Agreement">
              {agreementLines(project.agreement, formatDate).map((line) => <li key={line}>{line}</li>)}
            </ul>
          )}
          <div className="pd-pills">
            <ProgramPill value={project.status} />
            <ProgramPill value={project.health} />
            <span className="pd-reason">{project.health_reason}</span>
          </div>
        </div>
        <div className="program-head-actions">
          {setup && (
            <button type="button" className="program-btn" onClick={() => openDialog({ kind: 'details' })}>Edit details</button>
          )}
          {formBReason ? (
            <button type="button" className="program-btn primary" disabled aria-describedby="pd-form-b-blocked">Export Form B</button>
          ) : (
            <Link className="program-btn primary" to={`/program-reports?project=${encodeURIComponent(project.code)}&report=form-b&format=pdf`}>
              Export Form B
            </Link>
          )}
          {formBReason && (
            <p className="pu-hint pd-form-b-blocked" id="pd-form-b-blocked" role="status"><span aria-hidden="true">⚠ </span>{formBReason}</p>
          )}
        </div>
      </div>

      <div aria-live="polite">
        {notice && <div className="program-banner ok">{notice}</div>}
      </div>

      <div className="pl-kpi-grid">
        <section className="program-card pl-kpi">
          <div className="pl-kpi-label">Total budget</div>
          <div className="pl-kpi-value pd-kpi-value">{peso(project.budget)}</div>
        </section>
        <section className="program-card pl-kpi">
          <div className="pl-kpi-label"><Tip>Spent</Tip></div>
          <div className="pl-kpi-value pd-kpi-value">{peso(project.spent)}</div>
          <div className="pl-kpi-sub">{formatPct(project.spent_pct)} of the budget</div>
        </section>
        <section className="program-card pl-kpi">
          <div className="pl-kpi-label">Remaining</div>
          <div className="pl-kpi-value pd-kpi-value">{peso(project.remaining)}</div>
        </section>
        <section className="program-card pl-kpi">
          <div className="pl-kpi-label">Time elapsed</div>
          <div className="pl-kpi-value pd-kpi-value">{formatPct(project.time_pct)}</div>
          <div className="pl-kpi-sub">{project.elapsed_days} of {project.total_days} days</div>
        </section>
      </div>

      <ProjectDocumentsSection projectId={project.id} />

      <section className="program-card">
        <div className="program-card-head">
          <div>
            <div className="program-card-title">Line-item budget (Form B)</div>
          </div>
          {setup && (
            <button type="button" className="program-btn" onClick={() => openDialog({ kind: 'line' })}>Add line item</button>
          )}
        </div>
        {setup && (
          <p className={`ld-hint${left < 0 ? ' is-risk' : ''}`} role="status">
            {left >= 0 ? `${peso(left)} left to allocate` : `⚠ ${peso(-left)} over the total budget`}
            {setup.allocations_locked && (
              <>
                {' · '}
                <Tip text="Verified expenses or payroll exist: allocated amounts change only through a realignment request.">
                  <span aria-hidden="true">🔒 </span>Locked
                </Tip>
              </>
            )}
          </p>
        )}
        <FundingWarnings warnings={formB?.warnings} />
        {sections.length === 0 ? (
          <p className="pl-inbox-empty">No line items recorded yet.</p>
        ) : (
          <FormBTable formB={formB} setup={setup} onOpen={openDialog} />
        )}
      </section>

      <section className="program-card">
        <div className="program-card-head">
          <div>
            <div className="program-card-title">Releases</div>
            <div className="program-card-sub">Per the MOA</div>
          </div>
          {setup && (
            <div className="program-head-actions">
              {tranches.length === 0 && funderTotal > 0 && (
                <span className="pd-split">
                  <button type="button" className="program-btn" disabled={splitBusy} onClick={splitReleases} aria-describedby="pd-split-hint">
                    {splitBusy ? 'Adding…' : 'Split 70% / 30%'}
                  </button>
                  <Tip
                    icon
                    id="pd-split-hint"
                    label="About Split 70% / 30%"
                    text={`Adds the first two releases (${peso(splitPreview[0])} and ${peso(splitPreview[1])}) on the project start date and halfway through the period, with the project's next release numbers. You can edit both afterwards.`}
                  />
                </span>
              )}
              <button type="button" className="program-btn" onClick={() => { setSplitError(''); openDialog({ kind: 'tranche' }); }}>Add release</button>
            </div>
          )}
        </div>
        {splitError && <div className="program-banner error" role="alert">{splitError}</div>}
        {renumberError && <div className="program-banner error" role="alert">{renumberError}</div>}
        {setup && funderTotal > 0 && (
          <p className={`ld-hint${releasePlan.unscheduled < 0 ? ' is-risk' : ''}`} role="status">
            Planned {peso(releasePlan.planned)} of {peso(releasePlan.funderTotal)} funder total ({formatShare(releasePlan.percent)})
            {releasePlan.unscheduled >= 0
              ? ` · ${peso(releasePlan.unscheduled)} not yet scheduled`
              : ` · ⚠ ${peso(-releasePlan.unscheduled)} over the funder total`}
          </p>
        )}
        {setup && setup.funder_total_source !== 'moa' && (
          <p className="pu-hint" role="note">
            <span aria-hidden="true">ⓘ </span>Enter the grant amount from the MOA for exact percentages.{' '}
            <button type="button" className="link-btn pd-hint-link" onClick={() => openDialog({ kind: 'details' })}>Edit details</button>
          </p>
        )}
        {setup?.can_renumber_releases && (
          <p className="pu-hint" id="pd-renumber-hint">
            <button type="button" className="link-btn pd-hint-link" disabled={renumberBusy} onClick={renumberReleases}>
              {renumberBusy ? 'Renumbering…' : 'Renumber by planned date'}
            </button>
          </p>
        )}
        {tranches.length === 0 ? (
          <p className="pl-inbox-empty">No releases scheduled.</p>
        ) : (
          <div className="program-scroll">
            <table className="program-table pd-table">
              <thead>
                <tr>
                  <th>No.</th>
                  <th>Planned date</th>
                  <th className="num">Amount</th>
                  <th>Status</th>
                  <th>Liquidation</th>
                  {setup && <th><span className="pa-sr-only">Actions</span></th>}
                </tr>
              </thead>
              <tbody>
                {tranches.map((tranche) => (
                  <tr key={tranche.id}>
                    <td>
                      <div className="project-name">{tranche.name}</div>
                      {tranche.milestone && <div className="project-code">{tranche.milestone}</div>}
                    </td>
                    <td>{formatDate(tranche.planned_release_date)}</td>
                    <td className="num">
                      {peso(tranche.amount)}
                      {funderTotal > 0 && <span className="project-code"> · {formatShare(releaseShare(tranche.amount, funderTotal))}</span>}
                    </td>
                    <td>
                      <ProgramPill
                        value={tranche.status}
                        tone={tranche.status === 'Released' ? 'healthy' : tranche.status === 'Overdue' ? 'risk' : 'neutral'}
                        icon={tranche.status === 'Released' ? '✓' : tranche.status === 'Overdue' ? '✕' : '•'}
                      />
                    </td>
                    <td>
                      {tranche.liquidation_status ? (
                        <ProgramPill
                          value={tranche.liquidation_status}
                          tone={LIQUIDATION_TONE[tranche.liquidation_status] ?? 'neutral'}
                          icon={tranche.liquidation_status === 'Not submitted' ? '⚠' : undefined}
                        />
                      ) : '—'}
                    </td>
                    {setup && (
                      <td className="pd-actions">
                        {setup.editable_tranches.includes(tranche.id) ? (
                          <RowMenu name={tranche.name} options={menuOptions(trancheActions(tranche, { editable: true }), openDialog)} />
                        ) : (
                          <span className="project-code">{tranche.status === 'Released' ? '🔒 Released' : '🔒 Release requested'}</span>
                        )}
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <section className="program-card">
        <div className="program-card-head">
          <div>
            <div className="program-card-title">Team</div>
          </div>
          <div className="program-head-actions">
            <Link className="program-card-link" to="/program-users">Users and roles</Link>
            {setup && assignable && (
              <button type="button" className="program-btn" onClick={() => { setAssignError(''); openDialog({ kind: 'assign' }); }}>Add member</button>
            )}
          </div>
        </div>
        {setup && !assignable && (
          <p className="pu-hint" role="note"><span aria-hidden="true">ⓘ </span>Only Ongoing and Planning projects take new members.</p>
        )}
        {team.length === 0 ? (
          <p className="pl-inbox-empty">No one is assigned yet.</p>
        ) : (
          <ul className="pd-list">
            {team.map((member) => (
              <li key={`${member.user_id}-${member.role_slug}`}>
                <b>{member.name}</b>
                <span>{member.role}</span>
              </li>
            ))}
          </ul>
        )}
      </section>

      <PersonnelSection key={teamVersion} projectId={project.id} teamCount={team.length} />

      <PurchaseRequestsSection projectId={project.id} />

      <section className="program-card">
        <div className="program-card-head">
          <div>
            <div className="program-card-title">Recent verified expenses</div>
          </div>
        </div>
        {expenses.length === 0 ? (
          <p className="pl-inbox-empty">No verified expenses yet.</p>
        ) : (
          <div className="program-scroll">
            <table className="program-table pd-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Line item</th>
                  <th>Payee</th>
                  <th className="num">Amount</th>
                  <th>Reference</th>
                </tr>
              </thead>
              <tbody>
                {expenses.map((expense) => (
                  <tr key={expense.id}>
                    <td>{formatDate(expense.date)}</td>
                    <td>
                      <div>{expense.line_item}</div>
                      <div className="project-code">{expense.description}</div>
                    </td>
                    <td>{expense.payee}</td>
                    <td className="num">{peso(expense.amount)}</td>
                    <td>{expense.reference_no}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <section className="program-card">
        <div className="program-card-head">
          <div>
            <div className="program-card-title">Recent activity</div>
          </div>
          <Link className="program-card-link" to={`/program-audit?project=${project.id}`}>Audit trail</Link>
        </div>
        {activity.length === 0 ? (
          <p className="pl-inbox-empty">No recorded activity yet.</p>
        ) : (
          <ul className="pd-list">
            {activity.map((entry) => (
              <li key={entry.id}>
                <b>{activityTitle(entry)}</b>
                <span>
                  {entry.user} · {formatDateTime(entry.created_at)}
                  {entry.reason ? ` · ${entry.reason}` : ''}
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>

      {setup && dialog?.kind === 'details' && (
        <ProjectFormDialog mode="edit" projectId={project.id} setup={setup} onClose={() => setDialog(null)} onSaved={saved} />
      )}
      {setup && dialog?.kind === 'line' && (
        <LineItemDialog projectId={project.id} item={dialog.item ?? null} setup={setup} onClose={() => setDialog(null)} onSaved={saved} />
      )}
      {setup && dialog?.kind === 'tranche' && (
        <TrancheDialog projectId={project.id} tranche={dialog.item ?? null} setup={setup} onClose={() => setDialog(null)} onSaved={saved} />
      )}
      {setup && dialog?.kind === 'assign' && (
        <ProgramAssignUserModal
          users={data.assignable_users ?? []}
          // assign_project carries the project's current Project Leader (greys it out for another leader).
          projects={[data.assign_project ?? { id: project.id, title: project.title, code: project.code, status: project.status, leader: null }]}
          defaultProjectId={project.id}
          busy={assignBusy}
          error={assignError}
          onSave={addMember}
          onClose={() => { if (!assignBusy) setDialog(null); }}
        />
      )}
      {dialog?.kind === 'removeLine' && (
        <RemoveDialog
          title="Remove line item"
          text={`Remove "${dialog.item.name}" (${peso(dialog.item.allocated)}) from the Line-Item Budget?`}
          url={dialog.url}
          onClose={() => setDialog(null)}
          onSaved={saved}
        />
      )}
      {dialog?.kind === 'removeTranche' && (
        <RemoveDialog
          title="Remove release"
          text={`Remove ${dialog.item.name} (${peso(dialog.item.amount)}, planned ${formatDate(dialog.item.planned_release_date)})?`}
          url={dialog.url}
          onClose={() => setDialog(null)}
          onSaved={saved}
        />
      )}
    </>
  );
}
