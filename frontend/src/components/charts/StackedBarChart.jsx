// Renders a two-series (PS/MOOE) stacked bar chart from monthly data like:
// [{ m: 'Jan', ps: 34, mooe: 22 }, ...] where ps/mooe are already scaled
// 0-183 (the chart's plot height in px). Callers pass pre-scaled values so
// this stays a pure presentation component with no unit-conversion logic.
export default function StackedBarChart({ data, ariaLabel }) {
  const plotBottom = 183;
  const barWidth = 34;
  const gap = 64;
  const startX = 58;

  return (
    <svg viewBox="0 0 640 220" width="100%" role="img" aria-label={ariaLabel}>
      <g stroke="var(--line)">
        {[10, 60, 110, 160, 205].map((y) => (
          <line key={y} x1="46" y1={y} x2="640" y2={y} />
        ))}
      </g>
      {data.map((d, i) => {
        const x = startX + i * gap;
        return (
          <g key={d.m}>
            <rect x={x} y={plotBottom - d.ps} width={barWidth} height={d.ps} fill="var(--maroon-soft)" />
            <rect x={x} y={plotBottom - d.ps - d.mooe} width={barWidth} height={d.mooe} fill="var(--gold)" />
            <text x={x + barWidth / 2} y="219" fontSize="11" fill="var(--muted)" textAnchor="middle" fontFamily="Public Sans">{d.m}</text>
          </g>
        );
      })}
    </svg>
  );
}
