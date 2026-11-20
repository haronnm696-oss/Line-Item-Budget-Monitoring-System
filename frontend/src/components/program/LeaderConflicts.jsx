import { Link } from 'react-router-dom';

/**
 * Project Leaders who lead more than one active project (older data; the
 * server never changes it automatically). Each one is named with the
 * server's wording ("Leads 3 active projects; reassign all but one") and a
 * link to each of those projects, where Edit details changes the leader.
 *
 * conflicts: GET /api/program/projects|users `leader_conflicts`.
 */
export default function LeaderConflicts({ conflicts }) {
  if (!Array.isArray(conflicts) || conflicts.length === 0) return null;
  return (
    <div className="program-banner warn leader-conflicts" role="status">
      <p>
        <span aria-hidden="true">⚠ </span>
        <b>A Project Leader leads one active project at a time.</b> These lead more than one:
      </p>
      <ul>
        {conflicts.map((conflict) => (
          <li key={conflict.user.id}>
            <Link to={`/program-users?user=${conflict.user.id}`}><b>{conflict.user.name}</b></Link>
            {' · '}{conflict.message}:{' '}
            {conflict.projects.map((project, index) => (
              <span key={project.id}>
                {index > 0 && ', '}
                <Link to={`/program-projects/${project.id}`}>{project.code}</Link>
              </span>
            ))}
          </li>
        ))}
      </ul>
    </div>
  );
}
