// A Project Leader leads only ONE active project (planning or ongoing) at a
// time; completed projects do not count. The server enforces it
// (ProjectLeaderRule); these helpers make the screens follow the same rule.

/** Active = not completed (status_key from GET /api/projects). */
export function isActiveProject(project) {
  const status = String(project?.status_key ?? project?.status ?? '').toLowerCase();
  return status !== 'completed';
}

const byDateDesc = (key) => (a, b) => String(b?.[key] ?? '').localeCompare(String(a?.[key] ?? '')) || (Number(b?.id) || 0) - (Number(a?.id) || 0);

/**
 * The Project Leader's projects (rows they lead): `active`, most recently
 * started first, and `past` (completed), most recently ended first.
 */
export function splitLeaderProjects(projects) {
  const list = Array.isArray(projects) ? projects : [];
  return {
    active: list.filter(isActiveProject).sort(byDateDesc('start_date')),
    past: list.filter((project) => !isActiveProject(project)).sort(byDateDesc('end_date')),
  };
}

/**
 * Which project the Project Leader's screens show: their one active project.
 * Older data may give them several; then the ?project= one when it is one of
 * them, else the most recently started. `others` are the other active ones
 * (listed in a warning until the Program Leader reassigns them).
 */
export function pickLeaderProject(projects, requested) {
  const { active, past } = splitLeaderProjects(projects);
  const project = active.find((item) => String(item.id) === String(requested)) ?? active[0] ?? null;
  const others = active.filter((item) => item !== project);
  return { projects: active, project, others, past };
}

/** "You lead 3 active projects…" (null with one or none). */
export function conflictWarning(others) {
  if (!others?.length) return null;
  const count = others.length + 1;
  return `You lead ${count} active projects. A Project Leader leads one active project at a time, so this page shows the most recently started one. Ask the Program Leader to reassign the others:`;
}

/**
 * Project leader dropdown (New project / Edit details): free people first,
 * then the others greyed with the active project they lead. Leading the
 * project being edited does not count, nor does anything when the project
 * itself is completed (`projectActive` false). The current choice stays
 * selectable so the form never loses it.
 *
 * leaders: [{ id, name, leads: [{ id, code, title }] }] (GET /api/program/project-options).
 */
export function leaderChoices(leaders, { projectId = null, selectedId = '', projectActive = true } = {}) {
  const rows = (Array.isArray(leaders) ? leaders : []).map((leader) => {
    const busy = projectActive
      ? (leader.leads ?? []).filter((project) => String(project.id) !== String(projectId))
      : [];
    const selected = String(leader.id) === String(selectedId);
    return {
      id: leader.id,
      name: leader.name,
      busyWith: busy,
      disabled: busy.length > 0 && !selected,
      label: busy.length > 0 ? `${leader.name} — leads ${busy.map((project) => project.code).join(', ')}` : leader.name,
    };
  });
  return [...rows.filter((row) => row.busyWith.length === 0), ...rows.filter((row) => row.busyWith.length > 0)];
}

/** A project is still active on `today` (YYYY-MM-DD) when its end date is not past; blank = active. */
export function endDateActive(endDate, today) {
  return !endDate || String(endDate) >= String(today);
}

/** A Project Leader with only completed projects. */
export const NO_ACTIVE_PROJECT_TEXT = 'You do not lead an active project right now. Ask the Program Leader to assign you to one.';

/** The leader_conflicts row of one person (Users and roles), or null. */
export function conflictFor(conflicts, userId) {
  return (Array.isArray(conflicts) ? conflicts : []).find((conflict) => String(conflict?.user?.id) === String(userId)) ?? null;
}
