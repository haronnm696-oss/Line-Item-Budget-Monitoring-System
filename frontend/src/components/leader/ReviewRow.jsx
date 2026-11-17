import { Link } from 'react-router-dom';
import ProgramPill from '../program/ProgramPill';
import { peso } from '../money';
import { formatDate } from '../../lib/datetime';

/**
 * One record waiting for (or decided by) the Project Leader: type pill,
 * title, details, who submitted it and when, amount, and "Review" when this
 * user may decide. Approve / Return live only in the side panel (RecordPanel),
 * so the record and its receipts are seen first. `onOpen` opens the panel on
 * this page; `openHref` links to it on Submissions. Otherwise the row says
 * what the record waits for (e.g. a payroll that includes the leader's own pay).
 */
export default function ReviewRow({ item, onOpen, openHref, showStage = false }) {
  const when = item.submitted_at ? formatDate(item.submitted_at) : null;

  return (
    <div className="ld-review-row">
      <div className="ld-review-main">
        <div className="ld-review-title">
          <ProgramPill value={item.type_label} />
          {onOpen ? (
            <button type="button" className="ld-link" onClick={() => onOpen(item)}>{item.title}</button>
          ) : openHref ? (
            <Link className="ld-link" to={openHref}>{item.title}</Link>
          ) : (
            <b>{item.title}</b>
          )}
          {showStage && <ProgramPill value={item.workflow_label} />}
        </div>
        {item.details && <div className="ld-review-details">{item.details}</div>}
        <div className="ld-review-meta">
          {item.submitted_by && <span>Submitted by {item.submitted_by.name}</span>}
          {when && <span>{when}</span>}
          <span>{item.documents_count > 0 ? `${item.documents_count} document${item.documents_count === 1 ? '' : 's'}` : 'No documents'}</span>
        </div>
        {item.return_reason && item.workflow_status === 'returned' && (
          <p className="ld-reason">Reason: “{item.return_reason}”</p>
        )}
      </div>

      <div className="ld-review-side">
        {item.amount != null && <b className="ld-amount">{peso(item.amount)}</b>}
        {item.can_review ? (
          <div className="ld-review-actions">
            {onOpen ? (
              <button type="button" className="program-btn sm primary" onClick={() => onOpen(item)}>Review</button>
            ) : (
              <Link className="program-btn sm primary" to={openHref}>Review</Link>
            )}
          </div>
        ) : (
          item.waiting_text && (
            <span className="ld-waiting">
              <span aria-hidden="true">◷</span> {item.waiting_text}
            </span>
          )
        )}
      </div>
    </div>
  );
}
