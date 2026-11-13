import { compactPeso } from '../money';

const MAX_BAR = 130;

/**
 * Planned tranche releases for the next four months, as plain CSS bars.
 * `releases` is the Overview payload's scheduled_releases (current month
 * first; the backend already folds overdue tranches into the current month).
 */
export default function ScheduledReleasesBars({ releases = [] }) {
  const months = releases.slice(0, 4).map((month) => ({ ...month, amount: Number(month.amount) || 0 }));
  const largest = Math.max(...months.map((month) => month.amount), 0);

  return (
    <section className="program-card pl-chart-card">
      <div className="program-card-head">
        <div>
          <div className="program-card-title">Scheduled releases</div>
          <div className="program-card-sub">Planned tranches, next four months</div>
        </div>
      </div>

      {largest <= 0 ? (
        <div className="pl-chart-body pl-chart-empty">No scheduled releases</div>
      ) : (
        <>
          <div className="pl-chart-body pl-release-chart">
            {months.map((month) => {
              const has = month.amount > 0;
              return (
                <div className="pl-release-col" key={month.month}>
                  <div className="pl-release-plot">
                    <span className={`pl-release-value${has ? '' : ' is-none'}`}>
                      {has ? compactPeso(month.amount) : 'None'}
                    </span>
                    <span
                      className={`pl-release-bar${has ? '' : ' is-none'}`}
                      style={has ? { height: `${(month.amount / largest) * MAX_BAR}px` } : undefined}
                      aria-hidden="true"
                    />
                  </div>
                  <span className="pl-release-month">{month.label}</span>
                </div>
              );
            })}
          </div>
          <p className="program-footnote">Overdue tranches count in the current month.</p>
        </>
      )}
    </section>
  );
}
