// Line chart comparing two spending series (e.g. Personnel Services vs
// Maintenance & Other Operating Expenses) across months.
export default function LineChart({ data, ariaLabel = 'Monthly expenditure trend' }) {
  const W = 640;
  const H = 240;
  const padL = 46;
  const padR = 18;
  const padT = 18;
  const padB = 30;
  const maxV = Math.max(1, ...data.map((d) => Math.max(d.ps, d.mooe)));
  const innerW = W - padL - padR;
  const innerH = H - padT - padB;
  const x = (i) => padL + (i * innerW) / Math.max(1, data.length - 1);
  const y = (v) => padT + innerH - (v / maxV) * innerH;
  const build = (key) =>
    data.map((d, i) => `${i === 0 ? 'M' : 'L'}${x(i).toFixed(1)},${y(d[key]).toFixed(1)}`).join(' ');
  const ticks = [0, 0.25, 0.5, 0.75, 1];

  return (
    <svg viewBox={`0 0 ${W} ${H}`} width="100%" role="img" aria-label={ariaLabel}>
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
          <text x={x(i)} y={H - 10} fontSize="10.5" fill="var(--muted)" textAnchor="middle" fontFamily="Public Sans">
            {d.m}
          </text>
        </g>
      ))}
      <text x={padL} y={12} fontSize="10" fill="var(--muted)" fontFamily="Public Sans">PS</text>
      <text x={padL + 30} y={12} fontSize="10" fill="var(--muted)" fontFamily="Public Sans">MOOE</text>
    </svg>
  );
}
