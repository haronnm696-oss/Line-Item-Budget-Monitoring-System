import Panel from './Panel';
import Chip from './Chip';
import { peso } from './money';

const clamp = (value) => Math.max(0, Math.min(100, Number(value) || 0));

function signedPeso(amount) {
  const value = Number(amount) || 0;
  return `${value > 0 ? '+' : value < 0 ? '−' : ''}${peso(Math.abs(value))}`;
}

export default function BurnRateCard({ burnRate }) {
  if (!burnRate) return null;

  const tone = burnRate.tone || 'neutral';
  const forecastTone = Number(burnRate.forecast_variance) > 0 ? 'over' : 'ok';

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
              <b>{burnRate.elapsed_pct}%</b>
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
              <b>{burnRate.spent_pct}%</b>
            </div>
            <div className="util">
              <div className="track">
                <div className={`fill ${tone}`} style={{ width: `${clamp(burnRate.spent_pct)}%` }} />
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
            <b className={burnRate.variance_pct > 0 ? 'over' : 'ok'}>
              {burnRate.variance_pct > 0 ? '+' : ''}{burnRate.variance_pct}%
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
            Forecast vs budget: <b className={forecastTone}>{signedPeso(burnRate.forecast_variance)}</b>
          </span>
        </div>
      </div>
    </Panel>
  );
}
