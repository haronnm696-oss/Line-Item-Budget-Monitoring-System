export default function StatCard({ label, value, sub }) {
  return (
    <div className="panel stat">
      <span className="label">{label}</span>
      <span className="value num">{value}</span>
      {sub && <span className="sub">{sub}</span>}
    </div>
  );
}
