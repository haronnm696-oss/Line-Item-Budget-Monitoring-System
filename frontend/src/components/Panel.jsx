export default function Panel({ title, hint, action, children, style }) {
  return (
    <div className="panel" style={style}>
      {title && (
        <div className="panel-head">
          <h3>{title}</h3>
          {hint && <span className="hint">{hint}</span>}
          {action && <span className="panel-action">{action}</span>}
        </div>
      )}
      {children}
    </div>
  );
}
