import { useEffect } from 'react';
import { Link } from 'react-router-dom';

const SUPPORT_EMAIL = (import.meta.env.VITE_SUPPORT_EMAIL || '').trim();

const STEPS = [
  { title: 'Request access', text: 'Register with your MSU email.' },
  {
    title: 'Get approved',
    text: 'The Program Leader reviews your request and assigns your role.',
  },
  {
    title: 'Start monitoring',
    text: 'Sign in to the dashboard made for your role.',
  },
];

const FEATURES = [
  {
    icon: 'list',
    title: 'Line-item budgets',
    text: 'Track personnel services and operating expenses by line item, split by the funder’s share and MSU’s counterpart.',
  },
  {
    icon: 'flag',
    title: 'Fund releases',
    text: 'Follow each tranche from its target date to release, with clear overdue alerts.',
  },
  {
    icon: 'shield',
    title: 'Approvals you can trace',
    text: 'Requests, approvals, and rejections with remarks, all recorded in a permanent audit trail.',
  },
  {
    icon: 'receipt',
    title: 'Receipts and verification',
    text: 'Attach receipts to expenses and let Finance/Admin verify them before they count.',
  },
  {
    icon: 'wallet',
    title: 'Payroll for project personnel',
    text: 'Compute salaries, allowances, and government deductions for personnel services.',
  },
  {
    icon: 'file',
    title: 'Funder-ready reports',
    text: 'Export the funder’s official line-item budget form, plus ledgers and summaries, as PDF or Excel.',
  },
];

const ROLES = [
  {
    name: 'Program Leader',
    text: 'Oversees every project and approves fund releases and new accounts.',
  },
  {
    name: 'Project Leader',
    text: 'Manages one project and submits requests.',
  },
  {
    name: 'Research Assistant',
    text: 'Encodes expenses and payroll.',
  },
  {
    name: 'Finance/Admin Staff',
    text: 'Verifies records and supports reporting.',
  },
  {
    name: 'Researcher',
    text: 'Views budgets and exports reports.',
  },
];

const FUNDERS = [
  'Government agencies',
  'Research councils',
  'Local government units',
  'Private organizations',
  'International partners',
];

function FeatureIcon({ name }) {
  const props = {
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 1.8,
    strokeLinecap: 'round',
    strokeLinejoin: 'round',
    'aria-hidden': 'true',
    focusable: 'false',
  };

  switch (name) {
    case 'list':
      return (
        <svg {...props}>
          <path d="M8 6h13M8 12h13M8 18h13" />
          <circle cx="3.5" cy="6" r="1" />
          <circle cx="3.5" cy="12" r="1" />
          <circle cx="3.5" cy="18" r="1" />
        </svg>
      );
    case 'flag':
      return (
        <svg {...props}>
          <path d="M5 21V4" />
          <path d="M5 5h11l-2 4 2 4H5" />
        </svg>
      );
    case 'shield':
      return (
        <svg {...props}>
          <path d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3Z" />
          <path d="m9 12 2 2 4-4" />
        </svg>
      );
    case 'receipt':
      return (
        <svg {...props}>
          <path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z" />
          <path d="M9 7h6M9 11h6M9 15h4" />
        </svg>
      );
    case 'wallet':
      return (
        <svg {...props}>
          <path d="M3 7.5A2.5 2.5 0 0 1 5.5 5H18v3" />
          <rect x="3" y="8" width="18" height="11" rx="2.5" />
          <path d="M16 13h3" />
          <circle cx="16" cy="13" r=".5" fill="currentColor" stroke="none" />
        </svg>
      );
    case 'file':
      return (
        <svg {...props}>
          <path d="M6 3h7l5 5v13H6V3Z" />
          <path d="M13 3v5h5" />
          <path d="M9 13h6M9 17h6" />
        </svg>
      );
    default:
      return null;
  }
}

function OutlineLogos({ className = 'landing-brand__logos', imageClassName = 'landing-brand__logo' }) {
  return (
    <span className={className}>
      <img
        className={imageClassName}
        src="/images/msu-seal-outline.png"
        alt="Mindanao State University seal"
        width="317"
        height="324"
      />
      <img
        className={imageClassName}
        src="/images/client-logo-outline.png"
        alt="Client logo"
        width="384"
        height="346"
      />
    </span>
  );
}

export default function Landing() {
  useEffect(() => {
    document.documentElement.classList.add('landing-scroll');
    return () => document.documentElement.classList.remove('landing-scroll');
  }, []);

  return (
    <div className="landing-page">
      <a className="landing-skip" href="#main">Skip to content</a>

      <header className="landing-hero">
        <div className="landing-container">
          <nav className="landing-nav" aria-label="Primary">
            <Link to="/" className="landing-brand" aria-label="LIB Monitoring System home">
              <OutlineLogos />
              <span className="landing-brand__name">LIB Monitoring System</span>
            </Link>

            <div className="landing-nav__right">
              <div className="landing-nav__links">
                <a className="landing-nav__link" href="#features">Features</a>
                <a className="landing-nav__link" href="#how">How it works</a>
                <a className="landing-nav__link" href="#roles">Roles</a>
              </div>
              <Link to="/login" className="landing-pill landing-pill--outline landing-pill--sm">
                Sign in
              </Link>
            </div>
          </nav>

          <div className="landing-hero__grid">
            <div className="landing-hero__copy">
              <p className="landing-eyebrow">Externally funded research · MSU–Main Campus</p>
              <h1>Track every line item of your research funding.</h1>
              <p className="landing-hero__lead">
                One place for budgets, fund releases, expenses, payroll, and funder-ready
                reports at Mindanao State University – Main Campus.
              </p>
              <div className="landing-actions">
                <Link to="/login" className="landing-pill landing-pill--light">Sign in</Link>
                <Link to="/register" className="landing-pill landing-pill--outline">
                  Request access
                </Link>
              </div>
            </div>

            <aside className="landing-sample" aria-label="Sample dashboard preview">
              <div className="landing-sample__head">
                <p className="landing-sample__title">All projects overview</p>
                <span className="landing-sample__note">Sample data</span>
              </div>

              <div className="landing-sample__tiles">
                <div className="landing-sample__tile">
                  <span>Total budget</span>
                  <strong>₱7,198,376.00</strong>
                </div>
                <div className="landing-sample__tile">
                  <span>Total disbursed</span>
                  <strong>₱3,951,310.00</strong>
                </div>
                <div className="landing-sample__tile">
                  <span>Pending approvals</span>
                  <strong>3</strong>
                </div>
                <div className="landing-sample__tile">
                  <span>Projects</span>
                  <strong>4</strong>
                </div>
              </div>

              <div className="landing-sample__projects">
                <div className="landing-sample__project">
                  <div className="landing-sample__project-row">
                    Enhancing Urban Mobility <span aria-hidden="true">·</span> 42.8% spent
                  </div>
                  <div className="landing-sample__track" aria-hidden="true">
                    <span className="landing-sample__fill" style={{ width: '42.8%' }} />
                  </div>
                </div>
                <div className="landing-sample__project">
                  <div className="landing-sample__project-row">
                    Halal Goat Value Chain <span aria-hidden="true">·</span> 81.0% spent
                  </div>
                  <div className="landing-sample__track" aria-hidden="true">
                    <span className="landing-sample__fill" style={{ width: '81%' }} />
                  </div>
                </div>
              </div>

              <div className="landing-sample__pills">
                <span className="landing-status landing-status--healthy">1 Healthy</span>
                <span className="landing-status landing-status--watch">1 Watch</span>
                <span className="landing-status landing-status--risk">1 At risk</span>
              </div>
            </aside>
          </div>
        </div>
      </header>

      <main id="main">
        <section id="how" className="landing-section landing-section--page">
          <div className="landing-container">
            <h2>How it works</h2>
            <p className="landing-sub">
              Accounts are reviewed before first use, so only authorized personnel can see
              project funds.
            </p>
            <ol className="landing-steps">
              {STEPS.map((step, index) => (
                <li className="landing-step" key={step.title}>
                  <span className="landing-step__number" aria-hidden="true">{index + 1}</span>
                  <div>
                    <h3>{step.title}</h3>
                    <p>{step.text}</p>
                  </div>
                </li>
              ))}
            </ol>
          </div>
        </section>

        <section id="features" className="landing-section landing-section--alt">
          <div className="landing-container">
            <h2>Everything a funded project needs</h2>
            <p className="landing-sub">From the approved budget to the final report, in one system.</p>
            <div className="landing-features">
              {FEATURES.map((feature) => (
                <article className="landing-feature" key={feature.title}>
                  <span className="landing-feature__icon" aria-hidden="true">
                    <FeatureIcon name={feature.icon} />
                  </span>
                  <h3>{feature.title}</h3>
                  <p>{feature.text}</p>
                </article>
              ))}
            </div>
          </div>
        </section>

        <section id="roles" className="landing-section landing-section--page">
          <div className="landing-container">
            <h2>Made for every role</h2>
            <p className="landing-sub">
              Each person lands on a dashboard built for their work and sees only what they need.
            </p>
            <div className="landing-roles">
              {ROLES.map((role) => (
                <article className="landing-role" key={role.name}>
                  <h3>{role.name}</h3>
                  <p>{role.text}</p>
                </article>
              ))}
            </div>
          </div>
        </section>

        <section className="landing-section landing-section--alt landing-funders">
          <div className="landing-container">
            <h2>Works with any funder</h2>
            <p className="landing-sub">
              Each funder gets its own report template, so exports match the format it expects.
            </p>
            <div className="landing-funders__pills">
              {FUNDERS.map((funder) => (
                <span className="landing-funder-pill" key={funder}>{funder}</span>
              ))}
            </div>
          </div>
        </section>

        <section className="landing-cta">
          <div className="landing-container">
            <h2>Ready to get started?</h2>
            <p>Sign in, or request access and wait for the Program Leader’s approval.</p>
            <div className="landing-cta__actions">
              <Link to="/login" className="landing-pill landing-pill--light">Sign in</Link>
              <Link to="/register" className="landing-pill landing-pill--outline">
                Request access
              </Link>
            </div>
          </div>
        </section>
      </main>

      <footer className="landing-footer">
        <div className="landing-container landing-footer__inner">
          <div className="landing-footer__brand">
            <OutlineLogos
              className="landing-footer__logos"
              imageClassName="landing-footer__logo"
            />
            <div>
              <p className="landing-footer__name">LIB Monitoring System</p>
              <p>MSU–Main Campus · Authorized personnel only</p>
            </div>
          </div>

          <div className="landing-footer__right">
            <p>
              Need help? Contact your Program Leader.
              {SUPPORT_EMAIL && (
                <>
                  {' '}
                  Email <a href={`mailto:${SUPPORT_EMAIL}`}>{SUPPORT_EMAIL}</a>.
                </>
              )}
            </p>
            <p className="landing-footer__links">
              <Link to="/terms">Terms of Service</Link>
              <span aria-hidden="true">·</span>
              <Link to="/privacy">Privacy Policy</Link>
            </p>
          </div>
        </div>
      </footer>
    </div>
  );
}
