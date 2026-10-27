import Panel from './Panel';
import Chip from './Chip';
import { peso } from './money';

const STATUS_LABEL = {
  released: 'Released',
  due: 'Due now',
  upcoming: 'Upcoming',
  delayed: 'Delayed',
};

const STATUS_TONE = {
  released: 'ok',
  due: 'warn',
  upcoming: 'neutral',
  delayed: 'over',
};

function formatDate(value) {
  if (!value) return '—';
  return new Date(`${value}T00:00:00`).toLocaleDateString('en-PH', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
}

export default function TrancheTimeline({ tranches }) {
  const items = tranches?.items ?? [];
  const summary = tranches?.summary ?? {
    total_count: 0,
    released_count: 0,
    released_amount: 0,
    upcoming_amount: 0,
    total_amount: 0,
    progress_pct: 0,
  };

  return (
    <Panel
      title="Tranche / Fund Release Tracker"
      hint="Milestone-based funding releases"
      action={<Chip tone="ok">{summary.released_count} of {summary.total_count} released</Chip>}
      style={{ marginBottom: 24 }}
    >
      {items.length === 0 ? (
        <div className="empty-state">No tranches have been configured for this project yet.</div>
      ) : (
        <div className="tranche-wrap">
          <div className="tranche-summary">
            <div>
              <span className="label">Released</span>
              <b>{peso(summary.released_amount)}</b>
            </div>
            <div>
              <span className="label">Upcoming</span>
              <b>{peso(summary.upcoming_amount)}</b>
            </div>
            <div className="tranche-progress">
              <div className="util">
                <div className="track">
                  <div className="fill" style={{ width: `${summary.progress_pct}%` }} />
                </div>
              </div>
              <span>{summary.progress_pct}% of budget released</span>
            </div>
          </div>

          <div className="tranche-track">
            {items.map((tranche, index) => {
              const status = tranche.display_status || tranche.status;
              return (
                <div className={`tranche-step${tranche.is_released ? ' is-released' : ''}`} key={tranche.id ?? tranche.sequence}>
                  <div className="tranche-rail">
                    <div className="tranche-dot">{tranche.is_released ? '✓' : tranche.sequence}</div>
                    {index < items.length - 1 && (
                      <div className={`tranche-line${tranche.is_released ? ' is-released' : ''}`} />
                    )}
                  </div>
                  <div className="tranche-card">
                    <div className="tranche-card-head">
                      <span className="tranche-seq">Tranche {tranche.sequence}</span>
                      <Chip tone={STATUS_TONE[status] || 'neutral'}>
                        {STATUS_LABEL[status] || status}
                      </Chip>
                    </div>
                    <b>{tranche.name}</b>
                    <div className="tranche-amount">{peso(tranche.amount)}</div>
                    <div className="tranche-date">
                      {tranche.is_released
                        ? `Released ${formatDate(tranche.released_at)}`
                        : `Target ${formatDate(tranche.planned_release_date)}`}
                    </div>
                    {tranche.notes && <p className="tranche-note">{tranche.notes}</p>}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}
    </Panel>
  );
}
