import Panel from './Panel';
import Chip from './Chip';
import { peso, formatPct, signedPct, varianceTone } from './money';

const clamp = (value) => Math.max(0, Math.min(100, Number(value) || 0));

function signedPeso(amount) {
  const value = Number(amount) || 0;
  return `${value > 0 ? '+' : value < 0 ? '−' : ''}${peso(Math.abs(value))}`;
}

/** Under-spending is a delivery risk, so it gets a warning tone, not green. */
function forecastTone(variance, budget) {
  const amount = Number(variance) || 0;
  const size = Number(budget) || 0;
  if (amount > 0) return 'over';
  if (size > 0 && Math.abs(amount) / size > 0.05) return 'warn';
  return 'ok';
}

export default function BurnRateCard({ burnRate }) {
  if (!burnRate) return null;

  const tone = burnRate.tone || 'neutral';
  const spendTone = varianceTone(burnRate.variance_pct);
  const forecast = forecastTone(burnRate.forecast_variance, burnRate.budget);

  return (
    <Panel
      title="Budget Burn Rate & Forecast"
      hint="Elapsed project time vs budget consumed"
      action={<Chip tone={tone}>{burnRate.status_label}</Chip>}
      style={{ height: '100%' }}
    >
      <div className="burn-body">
        <div className={`burn-banner ${tone}`}>{burnRate.message}</div>

        <div className="burn-bars">
          <div>
            <div className="burn-label">
              <span>Time elapsed</span>
              <b>{formatPct(burnRate.elapsed_pct)}</b>
            </div>
            <div className="util">
              <div className="track">
                <div className="fill time" style={{ width: `${clamp(burnRate.elapsed_pct)}%` }} />
              </div>
            </div>
          </div>
          <div>
            <div className="burn-label">
              <span>Budget spent</span>
              <b>{formatPct(burnRate.spent_pct)}</b>
            </div>
            <div className="util">
              <div className="track">
                <div className={`fill ${spendTone}`} style={{ width: `${clamp(burnRate.spent_pct)}%` }} />
              </div>
            </div>
          </div>
        </div>

        <div className="burn-metrics">
          <div>
            <span>Monthly burn</span>
            <b>{peso(burnRate.monthly_burn)}</b>
          </div>
          <div>
            <span>Forecast at completion</span>
            <b>{peso(burnRate.projected_total)}</b>
          </div>
          <div>
            <span>Time/budget variance</span>
            <b className={spendTone}>
              {signedPct(burnRate.variance_pct)}
            </b>
          </div>
          <div>
            <span>Days remaining</span>
            <b>{burnRate.days_remaining}</b>
          </div>
        </div>

        <div className="burn-foot">
          <span>
            {burnRate.months_elapsed} months elapsed of {Math.max(1, Math.round(burnRate.total_days / 30.44))}
          </span>
          <span>
            Forecast vs budget: <b className={forecast}>{signedPeso(burnRate.forecast_variance)}</b>
          </span>
        </div>
      </div>
    </Panel>
  );
}
