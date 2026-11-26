import { useId, useState } from 'react';
import { Link } from 'react-router-dom';
import { formatDateTime, formatShortDateTime } from '../lib/datetime';
import { nextExpanded, rowClickToggles } from '../lib/auditLogList';

const TONE_ICON = { success: '✓', danger: '✕', neutral: '•' };

/**
 * The compact Audit log list, shared by the Program Leader (/program-audit)
 * and the Project Leader / Finance page (/audit). One line per entry:
 * Time, User, Action, Record, Project and a "▸" toggle. The change details
 * are collapsed; clicking the row or the toggle opens them under the row,
 * full width, and only one entry is open at a time. Below 720px each entry
 * is a two-line card (styles: .au-* in global.css).
 *
 * @param {object} props
 * @param {Array<{
 *   id: string|number, time: string, user: import('react').ReactNode,
 *   action: { label: string, tone?: string }, record: import('react').ReactNode, recordText: string,
 *   project: { code?: string|null, title: string, href?: string|null } | null,
 *   details: import('react').ReactNode | null,
 * }>} props.rows
 */
export default function AuditLogList({ rows, label = 'Audit entries' }) {
  const [openId, setOpenId] = useState(null);
  const baseId = useId();

  return (
    <div className="au-scroll">
      <table className="program-table au-table" aria-label={label}>
        <colgroup>
          <col className="au-col-time" />
          <col className="au-col-user" />
          <col className="au-col-action" />
          <col />
          <col className="au-col-project" />
          <col className="au-col-toggle" />
        </colgroup>
        <thead>
          <tr>
            <th scope="col">Time</th>
            <th scope="col">User</th>
            <th scope="col">Action</th>
            <th scope="col">Record</th>
            <th scope="col">Project</th>
            <th scope="col"><span className="pa-sr-only">Details</span></th>
          </tr>
        </thead>
        {rows.map((row) => {
          const open = openId === row.id;
          const detailsId = `${baseId}-${row.id}`;
          const toggle = () => setOpenId((current) => nextExpanded(current, row.id));
          const canOpen = Boolean(row.details);
          return (
            <tbody key={row.id} className={open ? 'is-open' : undefined}>
              <tr
                className={`au-row${canOpen ? ' can-open' : ''}`}
                onClick={canOpen ? (event) => { if (rowClickToggles(event.target)) toggle(); } : undefined}
              >
                <td className="au-c-time">
                  <time dateTime={row.time} title={formatDateTime(row.time)}>{formatShortDateTime(row.time)}</time>
                </td>
                <td className="au-c-user">{row.user}</td>
                <td className="au-c-action">
                  <span className={`au-pill ${row.action.tone ?? 'neutral'}`} title={row.action.label}>
                    <span aria-hidden="true">{TONE_ICON[row.action.tone] ?? '•'}</span>
                    <span className="au-pill-text">{row.action.label}</span>
                  </span>
                </td>
                <td className="au-c-record" title={row.recordText}>{row.record}</td>
                <td className="au-c-project">
                  {row.project && (row.project.href ? (
                    <Link className="au-link" to={row.project.href} title={row.project.title}>{row.project.code || row.project.title}</Link>
                  ) : (
                    <span title={row.project.title}>{row.project.code || row.project.title}</span>
                  ))}
                </td>
                <td className="au-c-toggle">
                  {canOpen && (
                    <button
                      type="button"
                      className="au-toggle"
                      aria-expanded={open}
                      aria-controls={detailsId}
                      aria-label={`${open ? 'Hide' : 'Show'} details: ${row.action.label}, ${row.recordText}`}
                      onClick={toggle}
                    >
                      <span aria-hidden="true">▸</span>
                    </button>
                  )}
                </td>
              </tr>
              {canOpen && (
                <tr className="au-detail" id={detailsId} hidden={!open}>
                  <td colSpan={6}>{open ? row.details : null}</td>
                </tr>
              )}
            </tbody>
          );
        })}
      </table>
    </div>
  );
}
