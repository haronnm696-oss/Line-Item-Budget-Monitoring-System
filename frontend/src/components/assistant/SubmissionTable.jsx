import { peso } from '../money';
import { canResubmit, returnedLine } from '../../lib/assistantStatus';
import StagePill from './StagePill';

/**
 * The Research Assistant's records: reference, description, amount, status
 * pill and the next step (a returned record shows the reviewer and reason).
 * Clicking the reference opens the record's side panel (onOpen). A returned
 * record its maker may edit shows "Edit and resubmit" in the row (onResubmit).
 * `pillType` = 'purchase_request' uses the purchase request stage names.
 */
export default function SubmissionTable({ items, onOpen, onResubmit, busyKey = null, showType = false, pillType = null, caption }) {
  return (
    <div className="ra-table-wrap">
      <table className="program-table ld-table ra-table">
        {caption && <caption className="pa-sr-only">{caption}</caption>}
        <thead>
          <tr>
            <th scope="col">Reference</th>
            <th scope="col">Description</th>
            <th scope="col" className="num">Amount</th>
            <th scope="col">Status and next step</th>
          </tr>
        </thead>
        <tbody>
          {items.map((item) => {
            const returned = returnedLine(item);
            return (
              <tr key={item.key}>
                <td data-label="Reference">
                  <button type="button" className="ld-link" onClick={() => onOpen(item)}>{item.reference}</button>
                  {showType && <div className="ra-type">{item.type_label}</div>}
                </td>
                <td data-label="Description">
                  <div className="ra-desc">{item.title}</div>
                  {item.details && <div className="ra-details">{item.details}</div>}
                </td>
                <td data-label="Amount" className="num ld-amount">{item.amount != null ? peso(item.amount) : '—'}</td>
                <td data-label="Status">
                  <StagePill status={item.workflow_status} type={pillType} />
                  {returned
                    ? <div className="ra-next is-returned">{returned}</div>
                    : item.next_step && <div className="ra-next">{item.next_step}</div>}
                  {onResubmit && canResubmit(item) && (
                    <div className="fin-row-actions ra-row-resubmit">
                      <button type="button" className="program-btn sm primary" disabled={busyKey === item.key} onClick={() => onResubmit(item)}>
                        {busyKey === item.key ? 'Opening…' : 'Edit and resubmit'}
                      </button>
                    </div>
                  )}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
