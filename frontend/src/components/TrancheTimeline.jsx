import Panel from './Panel';
import Chip from './Chip';
import { peso, formatPct } from './money';

const STATUS_LABEL = {
  released: 'Released',
  due: 'Due now',
  overdue: 'Overdue',
  upcoming: 'Upcoming',
  delayed: 'Delayed',
};

const STATUS_TONE = {
  released: 'ok',
  due: 'warn',
  overdue: 'over',
  upcoming: 'neutral',
  delayed: 'over',
};

const DAY_MS = 86400000;

function formatDate(value) {
  if (!value) return '—';
  return new Date(`${value}T00:00:00`).toLocaleDateString('en-PH', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
}

function startOfToday() {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return today;
}

function resolveStatus(tranche, today) {
  if (tranche.is_released) return 'released';
  const planned = tranche.planned_release_date
    ? new Date(`${tranche.planned_release_date}T00:00:00`)
    : null;
  if (planned && planned < today) return 'overdue';
  return tranche.display_status || tranche.status;
}

export default function TrancheTimeline({ tranches, totalBudget, counterpartBudget }) {
  const items = tranches?.items ?? [];
  const summary = tranches?.summary ?? {
    total_count: 0,
    released_count: 0,
    released_amount: 0,
    upcoming_amount: 0,
    total_amount: 0,
    progress_pct: 0,
  };

  const today = startOfToday();
  const total = Number(summary.total_amount) || Number(totalBudget) || 0;
  const counterpart = Number(counterpartBudget) || 0;

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
              <span className="label">Released (incl. counterpart)</span>
              <b>{peso(summary.released_amount)}</b>
              <span className="tranche-sub">
                {formatPct(summary.progress_pct)} of {peso(total)} total project budget
                {counterpart > 0 ? `; MSU counterpart is ${peso(counterpart)}` : ''}.
              </span>
            </div>
            <div>
              <span className="label">Upcoming</span>
              <b>{peso(summary.upcoming_amount)}</b>
              <span className="tranche-sub">Not yet released.</span>
            </div>
            <div className="tranche-progress">
              <div className="util">
                <div className="track">
                  <div className="fill" style={{ width: `${Math.min(100, Number(summary.progress_pct) || 0)}%` }} />
                </div>
              </div>
              <span>{formatPct(summary.progress_pct)} of total project budget released</span>
            </div>
          </div>

          <div className="tranche-track">
            {items.map((tranche, index) => {
              const status = resolveStatus(tranche, today);
              const planned = tranche.planned_release_date
                ? new Date(`${tranche.planned_release_date}T00:00:00`)
                : null;
              const daysLate = planned ? Math.max(1, Math.round((today - planned) / DAY_MS)) : 0;

              return (
                <div className={`tranche-step${tranche.is_released ? ' is-released' : ''}`} key={tranche.id ?? tranche.sequence}>
                  <div className="tranche-rail">
                    <div className="tranche-dot">{tranche.is_released ? '✓' : tranche.sequence}</div>
                    {index < items.length - 1 && (
                      <div className={`tranche-line${tranche.is_released ? ' is-released' : ''}`} />
                    )}
                  </div>
                  <div className={`tranche-card${status === 'overdue' ? ' is-overdue' : ''}`}>
                    <div className="tranche-card-head">
                      <span className="tranche-seq">Tranche {tranche.sequence}</span>
                      <Chip tone={STATUS_TONE[status] || 'neutral'}>
                        {STATUS_LABEL[status] || status}
                      </Chip>
                    </div>
                    <b>{tranche.name}</b>
                    <div className="tranche-amount">{peso(tranche.amount)}</div>
                    <div className={`tranche-date${status === 'overdue' ? ' over' : ''}`}>
                      {tranche.is_released
                        ? `Released ${formatDate(tranche.released_at)}`
                        : status === 'overdue'
                          ? `Target ${formatDate(tranche.planned_release_date)} · ${daysLate} day${daysLate === 1 ? '' : 's'} overdue`
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
