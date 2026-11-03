import Panel from '../Panel';
import Chip from '../Chip';
import { compactPeso, peso } from '../money';

export default function ReleaseForecast({ forecast }) {
  const items = Array.isArray(forecast) ? forecast : [];
  const total = items.reduce((sum, month) => sum + Number(month.amount || 0), 0);
  const max = Math.max(1, ...items.map((month) => Number(month.amount) || 0));
  const currentReleases = Array.isArray(items[0]?.releases) ? items[0].releases : [];

  return (
    <Panel
      title="Release forecast"
      hint="Scheduled tranches · next four months"
      action={<Chip tone="neutral">{compactPeso(total)}</Chip>}
      style={{ height: '100%' }}
    >
      {items.every((month) => Number(month.amount) === 0) ? (
        <div className="empty-state">No scheduled releases in the next four months.</div>
      ) : (
        <>
          <div className="pd-forecast">
            {items.map((month, index) => {
              const amount = Number(month.amount) || 0;
              const height = amount > 0 ? Math.max(5, (amount / max) * 100) : 0;
              const names = (month.releases ?? [])
                .map((release) => `${release.project_code} ${release.name} (${peso(release.amount)})`)
                .join('; ');
              const label = `${month.label}: ${peso(amount)} scheduled${names ? ` — ${names}` : ''}`;

              return (
                <div className="pd-forecast-col" key={month.month}>
                  <span className="pd-forecast-amount">{amount > 0 ? compactPeso(amount) : '—'}</span>
                  <div
                    className="pd-forecast-track"
                    role="img"
                    aria-label={label}
                    title={label}
                  >
                    <span
                      className={`pd-forecast-bar${index === 0 ? ' current' : ''}`}
                      style={{ height: `${height}%` }}
                    />
                  </div>
                  <span className="pd-forecast-label">
                    {month.label}
                    {index === 0 ? ' · now' : ''}
                  </span>
                </div>
              );
            })}
          </div>
          {currentReleases.length > 0 && (
            <div className="pd-forecast-current">
              <span className="pd-forecast-current-title">Current month releases</span>
              {currentReleases.map((release) => (
                <div className="pd-forecast-release" key={`${release.project_code}-${release.id}`}>
                  <span>
                    {release.project_code} · {release.name}
                    {release.overdue ? ' · overdue' : ''}
                  </span>
                  <b>{peso(release.amount)}</b>
                </div>
              ))}
            </div>
          )}
          <div className="pd-forecast-foot">
            <span>Overdue tranches are counted in the current month.</span>
            <b>{peso(total)}</b>
          </div>
        </>
      )}
    </Panel>
  );
}
