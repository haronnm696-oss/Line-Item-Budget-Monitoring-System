// Line chart comparing two spending series (e.g. Personnel Services vs
// Maintenance & Other Operating Expenses) across months. Values are in
// thousands of pesos; the axis title makes that explicit.
export default function LineChart({ data, ariaLabel = 'Monthly expenditure trend' }) {
  const W = 640;
  const H = 250;
  const padL = 62;
  const padR = 18;
  const padT = 20;
  const padB = 34;
  const rawMax = Math.max(1, ...data.map((d) => Math.max(d.ps, d.mooe)));
  // Round the top tick up to a clean ₱20K so the axis reads 0/20/40/60/80.
  const maxV = Math.max(20, Math.ceil(rawMax / 20) * 20);
  const innerW = W - padL - padR;
  const innerH = H - padT - padB;
  const x = (i) => padL + (i * innerW) / Math.max(1, data.length - 1);
  const y = (v) => padT + innerH - (v / maxV) * innerH;
  const build = (key) =>
    data.map((d, i) => `${i === 0 ? 'M' : 'L'}${x(i).toFixed(1)},${y(d[key]).toFixed(1)}`).join(' ');
  const ticks = [0, 0.25, 0.5, 0.75, 1];

  return (
    <div className="line-chart">
      <div className="legend chart-legend">
        <span><i style={{ background: 'var(--maroon-soft)' }} /> Personnel Services (PS)</span>
        <span><i style={{ background: 'var(--gold)' }} /> MOOE</span>
      </div>
      <svg viewBox={`0 0 ${W} ${H}`} width="100%" role="img" aria-label={ariaLabel}>
        <text
          x={14}
          y={padT + innerH / 2}
          fontSize="10"
          fill="var(--muted)"
          textAnchor="middle"
          fontFamily="Public Sans"
          transform={`rotate(-90 14 ${padT + innerH / 2})`}
        >
          ₱ thousands
        </text>

        {ticks.map((t) => (
          <g key={t}>
            <line x1={padL} x2={W - padR} y1={y(maxV * t)} y2={y(maxV * t)} stroke="var(--line)" strokeWidth="1" />
            <text x={padL - 8} y={y(maxV * t) + 4} fontSize="10" fill="var(--muted)" textAnchor="end" fontFamily="Public Sans">
              {Math.round(maxV * t)}
            </text>
          </g>
        ))}

        <path d={build('ps')} fill="none" stroke="var(--maroon-soft)" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" />
        <path d={build('mooe')} fill="none" stroke="var(--gold)" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" />

        {data.map((d, i) => (
          <g key={d.m}>
            <circle cx={x(i)} cy={y(d.ps)} r="3.5" fill="var(--maroon-soft)" />
            <circle cx={x(i)} cy={y(d.mooe)} r="3.5" fill="var(--gold)" />
            <text x={x(i)} y={H - 12} fontSize="10.5" fill="var(--muted)" textAnchor="middle" fontFamily="Public Sans">
              {d.m}
            </text>
          </g>
        ))}
      </svg>
    </div>
  );
}
