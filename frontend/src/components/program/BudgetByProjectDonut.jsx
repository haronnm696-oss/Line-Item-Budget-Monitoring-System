import { compactPeso, formatPct } from '../money';

const COLORS = ['#511420', '#BE9A3E', '#C98B95', '#D6CBBB', '#6F1D2A'];
const RADIUS = 60;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

/**
 * Share of the portfolio budget per project, drawn as an SVG donut.
 * `projects` is the Overview payload's project list (already funder-filtered).
 */
export default function BudgetByProjectDonut({ projects = [] }) {
  const rows = projects
    .map((project) => ({ ...project, budget: Number(project.budget) || 0 }))
    .filter((project) => !(String(project.status).toLowerCase() === 'closed' && project.budget <= 0))
    .sort((a, b) => b.budget - a.budget);
  const total = rows.reduce((sum, project) => sum + project.budget, 0);

  let offset = 0;
  const segments = total > 0
    ? rows.map((project, index) => {
      const pct = project.budget / total;
      const length = pct * CIRCUMFERENCE;
      const segment = {
        id: project.id,
        name: project.title,
        pct: pct * 100,
        color: COLORS[index % COLORS.length],
        length,
        offset: -offset,
      };
      offset += length;
      return segment;
    })
    : [];

  const label = segments.map((segment) => `${segment.name} ${formatPct(segment.pct)}`).join(', ');

  return (
    <section className="program-card pl-chart-card">
      <div className="program-card-head">
        <div>
          <div className="program-card-title">Budget by project</div>
          <div className="program-card-sub">
            {total > 0 ? `Share of the ${compactPeso(total)} portfolio` : 'Share of the portfolio'}
          </div>
        </div>
      </div>

      {segments.length === 0 ? (
        <div className="program-empty pl-chart-empty">No budget data yet</div>
      ) : (
        <div className="pl-donut">
          <div className="pl-donut-figure">
            <svg
              width="150"
              height="150"
              viewBox="0 0 150 150"
              role="img"
              aria-label={`Budget by project: ${label}`}
            >
              <g transform="rotate(-90 75 75)">
                {segments.map((segment) => (
                  <circle
                    key={segment.id}
                    cx="75"
                    cy="75"
                    r={RADIUS}
                    fill="none"
                    stroke={segment.color}
                    strokeWidth="26"
                    strokeDasharray={`${segment.length} ${CIRCUMFERENCE}`}
                    strokeDashoffset={segment.offset}
                  />
                ))}
              </g>
            </svg>
            <div className="pl-donut-center" aria-hidden="true">
              <b>{compactPeso(total)}</b>
              <span>{segments.length} project{segments.length === 1 ? '' : 's'}</span>
            </div>
          </div>

          <ul className="pl-donut-legend">
            {segments.map((segment) => (
              <li key={segment.id}>
                <span className="pl-donut-swatch" style={{ background: segment.color }} aria-hidden="true" />
                <span className="pl-donut-name" title={segment.name}>{segment.name}</span>
                <b>{formatPct(segment.pct)}</b>
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  );
}
