/** Project selector for the page head; shown only when there is more than one project. */
export default function ProjectSelect({ picker, id = 'project-select' }) {
  if (picker.projects.length < 2) return null;
  return (
    <div className="program-field">
      <label htmlFor={id}>Project</label>
      <select id={id} className="program-select" value={picker.projectId ?? ''} onChange={(event) => picker.choose(event.target.value)}>
        {picker.projects.map((project) => (
          <option key={project.id} value={project.id}>{project.title} ({project.code})</option>
        ))}
      </select>
    </div>
  );
}
