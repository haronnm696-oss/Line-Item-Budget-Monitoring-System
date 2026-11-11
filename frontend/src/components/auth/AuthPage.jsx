import { Link } from 'react-router-dom';

const ICONS = {
  user: (
    <>
      <circle cx="12" cy="8" r="3.5" />
      <path d="M5.5 20a6.5 6.5 0 0 1 13 0" />
    </>
  ),
  mail: (
    <>
      <rect x="3" y="5" width="18" height="14" rx="2.5" />
      <path d="m4 7 8 6 8-6" />
    </>
  ),
  phone: (
    <path d="M7 3.5h3l1.5 4-2 1.5a12 12 0 0 0 5.5 5.5l1.5-2 4 1.5v3a2 2 0 0 1-2.2 2A17 17 0 0 1 5 5.7 2 2 0 0 1 7 3.5Z" />
  ),
  id: (
    <>
      <rect x="3" y="5" width="18" height="14" rx="2.5" />
      <circle cx="8.5" cy="10.5" r="2" />
      <path d="M5.5 16c.4-1.7 1.6-2.5 3-2.5s2.6.8 3 2.5" />
      <path d="M14 10h4M14 14h4" />
    </>
  ),
  briefcase: (
    <>
      <rect x="3" y="7" width="18" height="12" rx="2.5" />
      <path d="M9 7V5.5A1.5 1.5 0 0 1 10.5 4h3A1.5 1.5 0 0 1 15 5.5V7" />
      <path d="M3 12h18" />
    </>
  ),
  building: (
    <>
      <rect x="4" y="3.5" width="16" height="17" rx="2" />
      <path d="M8 8h1.5M14.5 8H16M8 12h1.5M14.5 12H16M8 16h1.5M14.5 16H16" />
      <path d="M10 20.5v-3h4v3" />
    </>
  ),
  lock: (
    <>
      <rect x="4" y="10" width="16" height="11" rx="2.5" />
      <path d="M8 10V7a4 4 0 0 1 8 0v3" />
    </>
  ),
  chevron: <path d="m6 9 6 6 6-6" />,
};

function hideBrokenImage(event) {
  event.currentTarget.style.display = 'none';
}

export function AuthIcon({ name, className = 'signin-control__icon' }) {
  return (
    <svg
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.6"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      {ICONS[name]}
    </svg>
  );
}

export function CheckIcon({ className = 'signin-success__check' }) {
  return (
    <svg
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      <path d="m5 12.5 4.5 4.5L19 7" />
    </svg>
  );
}

export function AuthLogoRow() {
  return (
    <div className="signin-brand__logos">
      <img
        className="signin-brand__logo"
        src="/images/msu-seal-outline.png"
        alt="Mindanao State University seal"
        onError={hideBrokenImage}
      />
      <img
        className="signin-brand__logo"
        src="/images/client-logo-outline.png"
        alt="Client logo"
        onError={hideBrokenImage}
      />
    </div>
  );
}

export function AuthMobileHead() {
  return (
    <div className="signin-mobile-head">
      <img
        src="/images/msu-seal.png"
        alt="Mindanao State University seal"
        onError={hideBrokenImage}
      />
      <span>LIB Monitoring System</span>
    </div>
  );
}

export function AuthBrandPanel({
  title,
  system = 'LIB Monitoring System',
  lead,
  steps = [],
  cta,
  footer = 'MSU–Main Campus · Authorized personnel only',
}) {
  return (
    <aside className="signin-brand">
      <span className="signin-brand__shape signin-brand__shape--circle" aria-hidden="true" />
      <span
        className="signin-brand__shape signin-brand__shape--diamond signin-brand__shape--diamond-top"
        aria-hidden="true"
      />
      <span
        className="signin-brand__shape signin-brand__shape--diamond signin-brand__shape--diamond-bottom"
        aria-hidden="true"
      />

      <div className="signin-brand__inner">
        <AuthLogoRow />

        <h2 className="signin-brand__title">{title}</h2>
        <p className="signin-brand__system">{system}</p>
        <p className="signin-brand__lead">{lead}</p>

        {steps.length > 0 && (
          <ol className="signin-brand__steps">
            {steps.map((step, index) => (
              <li className="signin-brand__step" key={step}>
                <span className="signin-brand__step-number" aria-hidden="true">{index + 1}</span>
                <span>{step}</span>
              </li>
            ))}
          </ol>
        )}

        {cta && (
          <Link to={cta.to} className="signin-brand__cta">
            {cta.label}
          </Link>
        )}
      </div>

      <p className="signin-brand__footer">{footer}</p>
    </aside>
  );
}

export function AuthPage({ brand, variant, children }) {
  const isForm = variant === 'register';
  return (
    <div className={`signin-page${isForm ? ' signin-page--tall' : ''}`}>
      <div className={`signin-card${isForm ? ' signin-card--wide' : ''}`}>
        <AuthBrandPanel {...brand} />
        <main className={`signin-main${isForm ? ' signin-main--form' : ''}`}>
          <AuthMobileHead />
          {children}
        </main>
      </div>
    </div>
  );
}

export function AuthField({
  id,
  label,
  icon,
  trailingIcon,
  error,
  errorId,
  hint,
  hintId,
  hintLive = false,
  className = '',
  children,
}) {
  return (
    <div className={`signin-field${className ? ` ${className}` : ''}`}>
      <label className="signin-label" htmlFor={id}>{label}</label>
      <div className="signin-control">
        {icon && <AuthIcon name={icon} />}
        {children}
        {trailingIcon && (
          <AuthIcon name={trailingIcon} className="signin-control__chevron" />
        )}
      </div>
      {error && (
        <p id={errorId || `${id}-error`} className="signin-error" aria-live="polite">
          {error}
        </p>
      )}
      {hint && (
        <p id={hintId} className="signin-hint" aria-live={hintLive ? 'polite' : undefined}>
          {hint}
        </p>
      )}
    </div>
  );
}

export function AuthSection({ title, children }) {
  return (
    <section className="signin-section">
      <h3 className="signin-section__title">{title}</h3>
      {children}
    </section>
  );
}

export function AuthAlert({ children }) {
  return (
    <div className="signin-alert" role="alert" aria-live="polite">
      {children}
    </div>
  );
}

export function AuthNote({ children }) {
  return <div className="signin-note">{children}</div>;
}

export function AuthPillButton({ className = '', children, ...props }) {
  return (
    <button className={`signin-submit${className ? ` ${className}` : ''}`} {...props}>
      {children}
    </button>
  );
}

export function AuthPasswordFeedback({ rules, id }) {
  return (
    <div className="signin-password-feedback" id={id}>
      <div className="signin-strength" aria-hidden="true">
        {rules.map((rule) => (
          <span
            className={`signin-strength__segment${rule.met ? ' is-met' : ''}`}
            key={rule.key}
          />
        ))}
      </div>
      <ul className="signin-rules" aria-label="Password requirements">
        {rules.map((rule) => (
          <li className={`signin-rule${rule.met ? ' is-met' : ''}`} key={rule.key}>
            <span className="signin-rule__mark" aria-hidden="true">{rule.met ? '✓' : '○'}</span>
            <span>{rule.label}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}
