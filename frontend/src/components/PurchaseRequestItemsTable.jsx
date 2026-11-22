import { peso } from './money';

/**
 * The item table of a purchase request (MSU Purchase Request form), read
 * only: quantity, unit, description, line item, estimated unit cost and
 * estimated cost, with the total. Used by the Research Assistant's panel,
 * the Project Leader's review panel, Finance/Admin and the Program Leader.
 */
export default function PurchaseRequestItemsTable({ items, total, caption = 'Items' }) {
  const rows = Array.isArray(items) ? items : [];
  if (rows.length === 0) return null;
  const sum = total ?? rows.reduce((value, item) => value + (Number(item.estimated_cost) || 0), 0);

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
          </tr>
        </thead>
        <tbody>
          {rows.map((item, index) => (
            <tr key={item.id ?? index}>
              <td className="num">{item.quantity}</td>
              <td>{item.unit}</td>
              <td>{item.description}</td>
              <td>
                {item.line_item_type ? `${item.line_item_type} · ` : ''}{item.line_item ?? '—'}
                {item.expense_linked && <div className="ld-muted">✓ Expense linked</div>}
              </td>
              <td className="num">{peso(item.unit_cost)}</td>
              <td className="num">{peso(item.estimated_cost)}</td>
            </tr>
          ))}
        </tbody>
        <tfoot>
          <tr>
            <th scope="row" colSpan={5} className="num">Total</th>
            <td className="num"><b>{peso(sum)}</b></td>
          </tr>
        </tfoot>
      </table>
    </div>
  );
}
