import { formatDate } from '../../lib/datetime';
import { peso } from '../money';

/**
 * Receipts added after an expense was verified. Information only: the
 * expense stays verified and is not verified again.
 */
export default function LateReceipts({ items, onOpen }) {
  if (!items || items.length === 0) return null;
  return (
    <div className="fin-late">
      <h3 className="ld-panel-sub"><span aria-hidden="true">ⓘ</span> Late receipts (for information)</h3>
      <ul className="ld-mini-list">
        {items.map((row) => (
          <li key={row.document_id}>
            <div>
              <b><button type="button" className="ld-link" onClick={() => onOpen(row)}>{row.reference}</button> · {row.title}</b>
              <span>Receipt added by {row.uploaded_by ?? '—'} on {formatDate(row.uploaded_at)} · verified {formatDate(row.verified_at)}</span>
            </div>
            <b className="ld-amount">{peso(row.amount)}</b>
          </li>
        ))}
      </ul>
    </div>
  );
}
