import { useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { normalizeRole } from '../lib/roles';
import { chooseProject, showProjectSwitch } from '../lib/projectChoice';
import ActionMenu from './ActionMenu';

/**
 * The project line under a page title: "<title> · <code> · <detail>", with
 * a "Switch project ▾" text button at the end when the user (Research
 * Assistant, Finance/Admin or Researcher) has more than one project. The
 * menu lists "<code> · <title>" with the current one checked; choosing one
 * sets ?project= and remembers it (lib/projectChoice.js), so every page
 * follows. Every role page uses this one line; none has its own picker.
 *
 * @param {object} props
 * @param {object} props.picker   useAssignedProject() result
 * @param {object} [props.project] { title, code } to show; defaults to picker.project
 * @param {string|string[]} [props.detail] text after the code (falsy parts are skipped)
 */
export default function ProjectLine({ picker, project = picker.project, detail = null }) {
  const { user } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();
  const parts = [project?.title, project?.code, ...[].concat(detail)].filter(Boolean);
  const canSwitch = showProjectSwitch(normalizeRole(user), picker.projects);

  function choose(projectId) {
    if (String(projectId) === String(picker.projectId)) return;
    const search = chooseProject({ userId: user?.id, role: picker.role, projectId, search: location.search });
    navigate({ pathname: location.pathname, search }, { replace: true });
  }

  return (
    <div className="program-sub project-line">
      <span>{parts.join(' · ')}</span>
      {canSwitch && (
        <ActionMenu
          fixed
          buttonClassName="project-switch-btn"
          buttonContent={<>Switch project <span aria-hidden="true">▾</span></>}
          menuLabel="Switch project"
          menuClassName="project-switch-menu"
          options={picker.projects.map((item) => ({
            key: String(item.id),
            checked: String(item.id) === String(picker.projectId),
            title: `${item.code} · ${item.title}`,
            label: (
              <span className="project-switch-option">
                <b>{item.code}</b> · <span className="project-switch-title">{item.title}</span>
              </span>
            ),
            onSelect: () => choose(item.id),
          }))}
        />
      )}
    </div>
  );
}
