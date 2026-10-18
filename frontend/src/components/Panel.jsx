export default function Panel({ title, hint, action, children }) {
  return (
    <div className="panel">
      {title && (
        <div className="panel-head">
          <h3>{title}</h3>
          {hint && <span className="hint">{hint}</span>}
          {action}
        </div>
      )}
      {children}
    </div>
  );
}
