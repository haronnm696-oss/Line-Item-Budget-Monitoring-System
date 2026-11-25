import { ENDPOINTS } from '../../api/endpoints';
import { useApiData } from '../../lib/useApiData';
import { useAssignedProject } from '../../lib/useAssignedProject';
import { notAssignedText } from '../../lib/myProjects';
import LoadState from '../../components/LoadState';
import PageTitle from '../../components/PageTitle';
import ProjectLine from '../../components/ProjectLine';
import FormBTable from '../../components/projects/FormBTable';

/**
 * Budget (Researcher, read-only): the line items in the R&D Form B layout
 * the Program Leader sees (PS / MOOE, Direct / Indirect cost, groups and
 * sub-groups, implementing agency and funder columns, sub-totals), with
 * Spent and Remaining. form_b comes from GET /projects/:id
 * (ProjectFinancialService::formB); FormBTable without `setup` has no row
 * menus.
 */
export default function ResearcherBudget() {
  const picker = useAssignedProject({ role: 'researcher' });
  const detail = useApiData(picker.projectId ? ENDPOINTS.project(picker.projectId) : null);
  const formB = detail.data?.form_b ?? null;
  const sections = Array.isArray(formB?.sections) ? formB.sections : [];

  return (
    <div className="ld-page">
      <div className="pl-head">
        <div>
          <PageTitle title="Budget" readOnly />
          <ProjectLine picker={picker} />
        </div>
      </div>

      <LoadState state={picker.state} emptyText={notAssignedText(picker.role)} isEmpty={picker.state.status === 'ready' && !picker.project}>
        <LoadState state={detail} loadingText="Loading line items…" emptyText="This project has no line items yet." isEmpty={sections.length === 0}>
          <section className="program-card">
            <div className="program-card-head">
              <div>
                <div className="program-card-title">Line-item budget (Form B)</div>
              </div>
            </div>
            <FormBTable formB={formB} remaining />
          </section>
        </LoadState>
      </LoadState>
    </div>
  );
}
