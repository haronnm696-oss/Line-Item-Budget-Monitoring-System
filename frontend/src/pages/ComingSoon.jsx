import { Link, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { normalizeRole } from '../lib/roles';
import { navItemFor } from '../layouts/roleNav';
import { ROUTE_BY_ROLE } from '../routes/access';

/** Placeholder for a menu item whose page is not built yet. */
export default function ComingSoon() {
  const { user } = useAuth();
  const location = useLocation();
  const code = normalizeRole(user);
  const item = navItemFor(code, location.pathname);
  const title = item?.label ?? 'This page';

  return (
    <>
      <div className="program-page-head">
        <div>
          <h1 className="program-h1">{title}</h1>
        </div>
      </div>
      <section className="program-card pa-empty" role="status">
        <span><strong>Coming soon.</strong> {title} is not available yet.</span>
        <Link className="pp-clear" to={ROUTE_BY_ROLE[code] ?? '/dashboard'}>Back to Overview</Link>
      </section>
    </>
  );
}
