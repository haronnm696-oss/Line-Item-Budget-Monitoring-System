import { peso } from './money';

/** Item status as text plus an icon (never colour alone). */
const STATUS = {
  open: ['○', 'Open'],
  linked: ['◷', 'Linked (pending)'],
  paid: ['✓', 'Paid'],
};

/**
 * The item table of a purchase request (MSU Purchase Request form), read
 * only: quantity, unit, description, line item, estimated unit cost,
 * estimated cost and status (Open, Linked (pending) or Paid, with the
 * expense reference), with the total. Used by the Research Assistant's
 * panel, the Project Leader's review panel, Finance/Admin and the Program
 * Leader. `showStatus` is false before approval (every item is open).
 */
export default function PurchaseRequestItemsTable({ items, total, caption = 'Items', showStatus = true }) {
  const rows = Array.isArray(items) ? items : [];
  if (rows.length === 0) return null;
  const sum = total ?? rows.reduce((value, item) => value + (Number(item.estimated_cost) || 0), 0);
  const withStatus = showStatus && rows.some((item) => item.status);

  return (
    <div className="ra-table-scroll pr-items">
      <table className="program-table ld-table pr-items-table">
        <caption className="pr-items-caption">{caption}</caption>
        <thead>
          <tr>
            <th scope="col" className="num">Qty</th>
            <th scope="col">Unit</th>
            <th scope="col">Description</th>
            <th scope="col">Line item</th>
            <th scope="col" className="num">Est. unit cost</th>
            <th scope="col" className="num">Est. cost</th>
            {withStatus && <th scope="col">Status</th>}
          </tr>
        </thead>
        <tbody>
          {rows.map((item, index) => {
            const [icon, label] = STATUS[item.status] ?? STATUS.open;
            return (
              <tr key={item.id ?? index}>
                <td className="num">{item.quantity}</td>
                <td>{item.unit}</td>
                <td>{item.description}</td>
                <td>{item.line_item_type ? `${item.line_item_type} · ` : ''}{item.line_item ?? '—'}</td>
                <td className="num">{peso(item.unit_cost)}</td>
                <td className="num">{peso(item.estimated_cost)}</td>
                {withStatus && (
                  <td className={`pr-item-status is-${item.status ?? 'open'}`}>
                    <span aria-hidden="true">{icon} </span>{item.status_label ?? label}
                    {item.expense_reference && <div className="ld-muted">{item.expense_reference}</div>}
                  </td>
                )}
              </tr>
            );
          })}
        </tbody>
        <tfoot>
          <tr>
            <th scope="row" colSpan={5} className="num">Total</th>
            <td className="num"><b>{peso(sum)}</b></td>
            {withStatus && <td />}
          </tr>
        </tfoot>
      </table>
    </div>
  );
}
