// Two-segment donut (PS spent, MOOE spent) over an implicit "unspent" track.
// psPct/mooePct are shares of the circle's circumference (2*pi*46 ≈ 289),
// so callers pass already-computed dasharray-ready numbers.
export default function DonutChart({ psLength, mooeLength, totalPct, circumference = 289 }) {
  return (
    <svg viewBox="0 0 120 120" width="120" height="120" role="img" aria-label={`Donut chart: ${(Number(totalPct) || 0).toFixed(1)}% of budget spent`}>
      <circle cx="60" cy="60" r="46" fill="none" stroke="var(--line)" strokeWidth="16" />
      <circle cx="60" cy="60" r="46" fill="none" stroke="var(--maroon-soft)" strokeWidth="16"
        strokeDasharray={`${psLength} ${circumference - psLength}`} transform="rotate(-90 60 60)" />
      <circle cx="60" cy="60" r="46" fill="none" stroke="var(--gold)" strokeWidth="16"
        strokeDasharray={`${mooeLength} ${circumference - mooeLength}`} strokeDashoffset={-psLength} transform="rotate(-90 60 60)" />
      <text x="60" y="57" textAnchor="middle" fontSize="19" fontWeight="800" fill="var(--ink)" fontFamily="Public Sans">{(Number(totalPct) || 0).toFixed(1)}%</text>
      <text x="60" y="73" textAnchor="middle" fontSize="9.5" fill="var(--muted)" fontFamily="Public Sans">spent</text>
    </svg>
  );
}
