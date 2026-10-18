// A budget-utilization bar: green under 75%, amber to 90%, red above —
// matches the thresholds used throughout the approved mockup.
export default function UtilBar({ pct, label }) {
  const tone = pct >= 90 ? 'over' : pct >= 75 ? 'warn' : '';
  return (
    <div className="util">
      <div className="track"><div className={`fill ${tone}`} style={{ width: `${Math.min(pct, 100)}%` }} /></div>
      <small className="num">{label ?? `${pct.toFixed(1)}%`}</small>
    </div>
  );
}
