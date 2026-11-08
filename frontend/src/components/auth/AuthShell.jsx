function CheckIcon() {
  return (
    <svg className="auth-brand__check" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
      <path
        d="M2.5 8.5 6 12l7.5-8"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

export function AuthBrandPanel({ title, lead, items = [], numbered = false }) {
  return (
    <aside className="auth-brand">
      <div className="auth-brand__logo">
        <span className="auth-brand__badge" aria-hidden="true">MSU</span>
        <span className="auth-brand__name">LIB Monitoring System</span>
      </div>

      <div className="auth-brand__content">
        <h2 className="auth-brand__title">{title}</h2>
        <span className="auth-brand__rule" aria-hidden="true" />
        <p className="auth-brand__lead">{lead}</p>

        {items.length > 0 && (numbered ? (
          <ol className="auth-brand__list auth-brand__list--numbered">
            {items.map((item, index) => (
              <li className="auth-brand__item" key={item}>
                <span className="auth-brand__number" aria-hidden="true">{index + 1}</span>
                <span>{item}</span>
              </li>
            ))}
          </ol>
        ) : (
          <ul className="auth-brand__list">
            {items.map((item) => (
              <li className="auth-brand__item" key={item}>
                <CheckIcon />
                <span>{item}</span>
              </li>
            ))}
          </ul>
        ))}
      </div>

      <div className="auth-brand__footer">MSU–Main Campus · Authorized personnel only</div>
    </aside>
  );
}

export default function AuthShell({ title, lead, items = [], numbered = false, maxWidth = 420, children }) {
  return (
    <div className="auth-page">
      <AuthBrandPanel title={title} lead={lead} items={items} numbered={numbered} />
      <main className="auth-main">
        <div className="auth-form" style={{ maxWidth }}>
          {children}
        </div>
      </main>
    </div>
  );
}
