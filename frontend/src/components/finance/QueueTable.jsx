import { formatDate } from '../../lib/datetime';
import StagePill from '../assistant/StagePill';
import { peso } from '../money';

/**
 * Verification queue rows: reference, description, amount, who approved it,
 * and Verify / Reject when this user may verify (otherwise the reason).
 * `actions` = useVerifyActions(); `onOpen` opens the side panel.
 */
export default function QueueTable({ items, actions, onOpen, showActions = true, caption }) {
  return (
    <div className="ra-table-wrap">
      <table className="program-table ld-table ra-table">
        {caption && <caption className="pa-sr-only">{caption}</caption>}
        <thead>
          <tr>
            <th scope="col">Reference</th>
            <th scope="col">Description</th>
            <th scope="col" className="num">Amount</th>
            <th scope="col">Status</th>
            {showActions && <th scope="col"><span className="pa-sr-only">Actions</span></th>}
          </tr>
        </thead>
        <tbody>
          {items.map((item) => {
            const busy = actions.busyKey === item.key;
            return (
              <tr key={item.key}>
                <td data-label="Reference">
                  <button type="button" className="ld-link" onClick={() => onOpen(item)}>{item.reference}</button>
                  <div className="ra-type">{item.type_label}</div>
                </td>
                <td data-label="Description">
                  <div className="ra-desc">{item.title}</div>
                  {item.details && <div className="ra-details">{item.details}</div>}
                </td>
                <td data-label="Amount" className="num ld-amount">{peso(item.amount)}</td>
                <td data-label="Status">
                  <StagePill status={item.workflow_status} />
                  <div className="ra-next">
                    {item.workflow_status === 'endorsed'
                      ? (item.approved_by ? `Approved by ${item.approved_by.name}${item.approved_at ? ` · ${formatDate(item.approved_at)}` : ''}` : item.approval_note)
                      : item.verified_by ? `Verified by ${item.verified_by.name} · ${formatDate(item.verified_at)}` : item.next_step}
                  </div>
                  {item.blocked_text && <div className="ra-next fin-blocked">⊘ {item.blocked_text}</div>}
                </td>
                {showActions && (
                  <td data-label="Actions">
                    {item.can_verify && (
                      <div className="fin-row-actions">
                        <button type="button" className="program-btn sm danger" disabled={busy} onClick={() => actions.reject(item)}>Reject</button>
                        <button type="button" className="program-btn sm primary" disabled={busy} onClick={() => actions.verify(item)}>{busy ? 'Saving…' : 'Verify'}</button>
                      </div>
                    )}
                  </td>
                )}
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
