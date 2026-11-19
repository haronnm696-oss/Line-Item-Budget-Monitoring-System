/**
 * The project's older typed counterpart / funder amount differs from what
 * the line items assign (ProjectFinancialService::fundingWarnings). Nothing
 * changes automatically; the warning goes once they match.
 */
export default function FundingWarnings({ warnings, className = 'program-banner warn' }) {
  if (!warnings?.length) return null;
  return (
    <div className={`${className} fb-warnings`} role="status">
      {warnings.map((warning) => <p key={warning.source}>⚠ {warning.message}</p>)}
    </div>
  );
}
