import Panel from '../Panel';
import Chip from '../Chip';
import { formatPct, peso } from '../money';

function ExceptionGroup({ title, hint, count, children }) {
  return (
    <div className="pd-exception-card">
      <div className="pd-exception-head">
        <b>{title}</b>
        <Chip tone={count ? 'warn' : 'ok'}>{count}</Chip>
      </div>
      <p className="pd-exception-sub">{hint}</p>
      {count === 0 ? <div className="pd-exception-empty">None flagged.</div> : children}
    </div>
  );
}

export default function ExceptionsPanel({ exceptions }) {
  const selfBenefiting = exceptions?.self_benefiting ?? [];
  const unused = exceptions?.unused_line_items ?? [];
  const realignments = exceptions?.realignments ?? [];
  const total = selfBenefiting.length + unused.length + realignments.length;

  return (
    <div className="pd-section">
      <Panel
        title="Exceptions & controls"
        hint="Items that need a control check before the next release"
        action={<Chip tone={total ? 'warn' : 'ok'}>{total} flagged</Chip>}
      >
        <div className="pd-exception-grid">
          <ExceptionGroup
            title="Self-benefiting expenses"
            hint="Program Leader indirect honoraria and similar related-party items."
            count={selfBenefiting.length}
          >
            {selfBenefiting.map((item) => (
              <div className="pd-exception-item" key={`${item.project_id}-${item.line_item}`}>
                <b>{item.line_item}</b>
                <span>{item.project}</span>
                <div className="pd-ex-line">
                  <span>{peso(item.spent)} / {peso(item.allocated)}</span>
                  <Chip tone="warn">{formatPct(item.spent_pct)}</Chip>
                </div>
              </div>
            ))}
          </ExceptionGroup>

          <ExceptionGroup
            title="Unused line items"
            hint="Zero spending after more than half of the project timeline has elapsed."
            count={unused.length}
          >
            {unused.map((item) => (
              <div className="pd-exception-item" key={`${item.project_id}-${item.line_item}`}>
                <b>{item.line_item}</b>
                <span>{item.project}</span>
                <div className="pd-ex-line">
                  <span>{peso(item.allocated)} allocated</span>
                  <Chip tone="warn">{formatPct(item.time_pct)} time</Chip>
                </div>
              </div>
            ))}
          </ExceptionGroup>

          <ExceptionGroup
            title="Realignments above 10%"
            hint="Pending transfers that exceed ten percent of the source line item."
            count={realignments.length}
          >
            {realignments.map((item) => (
              <div className="pd-exception-item" key={item.id}>
                <b>{peso(item.amount)} realignment</b>
                <span>{item.project}</span>
                <div className="pd-ex-line">
                  <span>{item.source} → {item.target ?? 'destination'}</span>
                  <Chip tone="warn">{item.threshold_pct}% threshold</Chip>
                </div>
              </div>
            ))}
          </ExceptionGroup>
        </div>
      </Panel>
    </div>
  );
}
