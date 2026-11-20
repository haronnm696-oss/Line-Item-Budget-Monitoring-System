import { Link } from 'react-router-dom';

/**
 * The project's older typed counterpart / funder amount differs from what
 * the line items assign (ProjectFinancialService::fundingWarnings). Nothing
 * changes automatically; the warning goes once they match.
 *
 * fixTo: the project page where the Program Leader fixes it (Reports); each
 * warning then links there.
 */
export default function FundingWarnings({ warnings, className = 'program-banner warn', fixTo }) {
  if (!warnings?.length) return null;
  return (
    <div className={`${className} fb-warnings`} role="status">
      {warnings.map((warning) => (
        <p key={warning.source}>
          ⚠ {warning.message}
          {fixTo && (
            <>
              {' '}
              <Link className="program-card-link" to={fixTo}>Fix on the project page</Link>
            </>
          )}
        </p>
      ))}
    </div>
  );
}
