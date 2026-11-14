import { compactPeso, peso } from '../money';

const MAX_BAR = 150;
const VALUE_BAND = 22;
const BASELINE = VALUE_BAND + MAX_BAR;
const HEIGHT = BASELINE + 26;
const BAR_WIDTH = 4.6; // percent of the chart width

function shortPeso(amount) {
  return amount > 0 ? compactPeso(amount) : '₱0';
}

/**
 * Verified expenses per month of the current year (descriptive only).
 * `months` is the Overview payload's monthly_expenses: 12 items with
 * { month, label, amount, is_future }. Future months are empty slots and are
 * never estimated.
 */
export default function MonthlyExpenseTrend({ months = [] }) {
  const year = months[0]?.month?.slice(0, 4) ?? String(new Date().getFullYear());
  const slots = months.slice(0, 12).map((month) => ({
    ...month,
    amount: month.is_future ? null : Number(month.amount) || 0,
  }));
  const actual = slots.filter((month) => !month.is_future);
  const total = actual.reduce((sum, month) => sum + month.amount, 0);
  const largest = Math.max(0, ...actual.map((month) => month.amount));
  const highest = actual.find((month) => month.amount === largest);
  const monthName = (month) =>
    new Date(`${month.month}-01T00:00:00`).toLocaleString('en-PH', { month: 'long' });

  const ariaLabel = `Spent per month, ${year}: ${slots
    .map((month) => `${monthName(month)} ${month.is_future ? 'not yet' : peso(month.amount)}`)
    .join(', ')}.`;

  return (
    <section className="program-card pl-chart-card pl-trend-card">
      <div className="program-card-head">
        <div>
          <div className="program-card-title">Monthly expense trend</div>
          <div className="program-card-sub">Spent per month, {year}</div>
        </div>
      </div>

      {slots.length === 0 || total <= 0 ? (
        <div className="pl-chart-body pl-chart-empty">No expenses recorded this year.</div>
      ) : (
        <>
          <svg
            className="pl-trend-svg"
            width="100%"
            height={HEIGHT}
            role="img"
            aria-label={ariaLabel}
          >
            <line x1="0" x2="100%" y1={BASELINE + 0.5} y2={BASELINE + 0.5} stroke="#EDE4D6" strokeWidth="1" />
            {slots.map((month, index) => {
              const center = ((index + 0.5) / slots.length) * 100;
              const x = `${center - BAR_WIDTH / 2}%`;
              const label = (
                <text className="pl-trend-month" x={`${center}%`} y={BASELINE + 18} textAnchor="middle">
                  {month.label}
                </text>
              );

              if (month.is_future) {
                return <g key={month.month}>{label}</g>;
              }

              const isZero = month.amount <= 0;
              const height = isZero ? 4 : Math.max(4, (month.amount / largest) * MAX_BAR);
              return (
                <g key={month.month}>
                  <rect
                    x={x}
                    y={BASELINE - height}
                    width={`${BAR_WIDTH}%`}
                    height={height}
                    rx={isZero ? 1 : 3}
                    fill={isZero ? '#D6CBBB' : '#6F1D2A'}
                  />
                  <text
                    className={`pl-trend-value${isZero ? ' is-zero' : ''}`}
                    x={`${center}%`}
                    y={BASELINE - height - 6}
                    textAnchor="middle"
                  >
                    {shortPeso(month.amount)}
                  </text>
                  {label}
                </g>
              );
            })}
          </svg>
          <div className="pl-trend-foot">
            <span>
              Highest month: <b>{highest ? monthName(highest) : '—'}</b> · <b>{peso(largest)}</b>
            </span>
            <span>
              Total this year: <b>{peso(total)}</b>
            </span>
          </div>
        </>
      )}
    </section>
  );
}
