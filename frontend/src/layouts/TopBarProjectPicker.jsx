/**
 * The one Project picker of the role pages (Research Assistant,
 * Finance/Admin, Researcher), in the top bar. RoleLayout shows it only when
 * the user has more than one project; the choice goes to ?project= and is
 * remembered per user (lib/projectChoice.js), so every page follows it.
 */
export default function TopBarProjectPicker({ projects, projectId, onChoose }) {
  return (
    <div className="topbar-project">
      <label htmlFor="topbar-project-select">Project</label>
      <select
        id="topbar-project-select"
        className="program-select"
        value={projectId ?? ''}
        onChange={(event) => onChoose(event.target.value)}
      >
        {projects.map((project) => (
          <option key={project.id} value={project.id}>{project.title} ({project.code})</option>
        ))}
      </select>
    </div>
  );
}
