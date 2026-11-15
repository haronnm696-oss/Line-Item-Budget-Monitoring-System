/** Select among the user's assigned projects (see useAssignedProject). */
export default function ProjectPicker({ picker, id = 'project-picker' }) {
  if (picker.projects.length < 2) return null;
  return (
    <div className="field" style={{ margin: 0, minWidth: 260 }}>
      <label htmlFor={id}>Project</label>
      <select id={id} value={picker.projectId ?? ''} onChange={(event) => picker.choose(event.target.value)}>
        {picker.projects.map((project) => (
          <option key={project.id} value={project.id}>{project.title} ({project.code})</option>
        ))}
      </select>
    </div>
  );
}
