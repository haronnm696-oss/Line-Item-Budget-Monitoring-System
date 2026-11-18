import { ratesLabel } from '../../lib/payrollRatesLabel';

/** Warning banner for PAYROLL_RATES_LABEL; renders nothing when it is not set. */
export default function RatesLabelBanner({ label }) {
  const text = ratesLabel(label);
  if (!text) return null;
  return (
    <div className="program-banner warn" role="note">
      <b><span aria-hidden="true">⚠</span> {text}</b>
    </div>
  );
}
