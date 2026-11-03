import Panel from '../Panel';
import Chip from '../Chip';

function dateParts(value) {
  if (!value) return { month: '—', day: '—' };
  const date = new Date(`${value}T00:00:00`);
  if (Number.isNaN(date.getTime())) return { month: '—', day: '—' };
  return {
    month: date.toLocaleDateString('en-PH', { month: 'short' }),
    day: date.getDate(),
  };
}

export default function ReportingCalendar({ deadlines }) {
  const items = Array.isArray(deadlines) ? deadlines : [];
  const overdue = items.filter((item) => item.tone === 'over').length;

  return (
    <Panel
      title="Reporting calendar"
      hint="Reports, liquidations, releases, and project closeouts"
      action={<Chip tone={overdue ? 'over' : 'ok'}>{overdue} overdue</Chip>}
      style={{ height: '100%' }}
    >
      {items.length === 0 ? (
        <div className="empty-state">No reporting deadlines yet.</div>
      ) : (
        <div className="pd-calendar">
          {items.map((item) => {
            const { month, day } = dateParts(item.date);
            return (
              <div className="pd-cal-row" key={item.id}>
                <div className={`pd-cal-date ${item.tone}`}>
                  <span>{month}</span>
                  <b>{day}</b>
                </div>
                <div className="pd-cal-main">
                  <b>{item.title}</b>
                  <span>{item.project}</span>
                </div>
                <span className={`pd-cal-status ${item.tone}`}>{item.status}</span>
              </div>
            );
          })}
        </div>
      )}
    </Panel>
  );
}
