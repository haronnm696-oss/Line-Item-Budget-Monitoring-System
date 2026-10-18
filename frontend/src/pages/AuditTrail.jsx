import { useEffect, useState } from 'react';
import Chip from '../components/Chip';
import { getWithFallback } from '../api/http';
import { ENDPOINTS } from '../api/endpoints';
import { auditLog } from '../data/mockData';

const ACTION_TONE = { created: 'neutral', updated: 'neutral', verified: 'ok', approved: 'ok', rejected: 'over' };
const ACTION_LABEL = { created: 'Created', updated: 'Updated', verified: 'Verified', approved: 'Approved', rejected: 'Rejected' };

function toCSV(rows, headers) {
  const escape = (v) => {
    if (v === null || v === undefined) return '';
    const s = String(v);
    if (s.includes(',') || s.includes('"') || s.includes('\n')) {
      return '"' + s.replace(/"/g, '""') + '"';
    }
    return s;
  };
  const headerRow = headers.map(escape).join(',');
  const dataRows = rows.map((r) => headers.map((h) => escape(r[h])).join(','));
  return [headerRow, ...dataRows].join('\n');
}

function downloadCSV(content, filename) {
  const blob = new Blob([content], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.setAttribute('href', url);
  a.setAttribute('download', filename);
  a.style.visibility = 'hidden';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

function buildPrintHTML(title, subtitle, rows, meta) {
  const thead = `
    <tr>
      <th>Timestamp</th><th>User</th><th>Action</th><th>Record</th><th>Record Type</th>
      <th>Change</th><th>IP Address</th>
    </tr>
  `;
  const tbody = rows.map((a) => `
    <tr>
      <td>${a.ts}</td>
      <td>${a.user}</td>
      <td>${ACTION_LABEL[a.action] || a.action}</td>
      <td>${a.record}</td>
      <td>${a.recordType}</td>
      <td>${a.change}</td>
      <td>${a.ip}</td>
    </tr>
  `).join('');

  return `
    <!DOCTYPE html>
    <html>
    <head>
      <meta charset="utf-8" />
      <title>${title}</title>
      <style>
        @page { size: A4; margin: 1.5cm; }
        body { font-family: 'Public Sans', system-ui, sans-serif; font-size: 10.5px; color: #221D1A; line-height: 1.4; }
        .no-print { display: none !important; }
        .letterhead { border-bottom: 3px solid #5E1B25; padding-bottom: 10px; margin-bottom: 16px; display: flex; align-items: center; gap: 12px; }
        .seal { width: 40px; height: 40px; border-radius: 50%; background: radial-gradient(circle at 35% 35%, #C9A227, #8a6d12); display: inline-flex; align-items: center; justify-content: center; font-weight: 800; color: #3d2f06; font-size: 12px; }
        .org h1 { font-size: 17px; font-weight: 800; margin: 0; color: #5E1B25; }
        .org p { font-size: 10px; color: #6E655E; margin: 2px 0 0; }
        .doc-title { font-size: 15px; font-weight: 700; margin: 10px 0 4px; color: #221D1A; }
        .doc-sub { font-size: 10.5px; color: #6E655E; margin-bottom: 14px; }
        .meta { font-size: 10px; color: #6E655E; margin-bottom: 14px; display: flex; gap: 24px; flex-wrap: wrap; }
        table { width: 100%; border-collapse: collapse; font-size: 10.5px; margin-top: 8px; }
        th { background: #F7F6F2; color: #6E655E; font-weight: 600; text-align: left; padding: 6px 8px; border: 1px solid #CFC9BC; font-size: 10px; }
        td { padding: 5px 8px; border: 1px solid #E4E0D7; vertical-align: top; }
        tr:nth-child(even) td { background: #FAF9F7; }
        .footer { margin-top: 20px; font-size: 9px; color: #6E655E; border-top: 1px solid #CFC9BC; padding-top: 8px; }
        .action-chip { display: inline-block; border-radius: 999px; padding: 1px 8px; font-size: 9.5px; font-weight: 600; }
        .action-created { background: #F7F6F2; color: #221D1A; border: 1px solid #CFC9BC; }
        .action-updated { background: #E9F3EE; color: #2F7D5B; }
        .action-verified { background: #E9F3EE; color: #2F7D5B; }
        .action-approved { background: #E9F3EE; color: #2F7D5B; }
        .action-rejected { background: #F8E7E4; color: #B3382C; }
      </style>
    </head>
    <body>
      <div class="letterhead">
        <div class="seal">MSU</div>
        <div class="org">
          <h1>Mindanao State University–Main Campus</h1>
          <p>Office of the Vice Chancellor for Research & Extension · LIB Monitoring System</p>
          <p>Monitoring Agency: <b>MOST-BARMM</b> · Implementing Agency: <b>MSU-Marawi</b></p>
        </div>
      </div>

      <div class="doc-title">${title}</div>
      <div class="doc-sub">${subtitle}</div>

      <div class="meta">
        <span><b>Generated:</b> ${new Date().toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' })}</span>
        ${meta.map((m) => `<span><b>${m.label}:</b> ${m.value}</span>`).join('')}
      </div>

      <table>
        <thead>${thead}</thead>
        <tbody>${tbody}</tbody>
      </table>

      <div class="footer">
        This is an immutable system audit log. Entries cannot be edited or deleted.<br />
        Mindanao State University–Main Campus · MOST R&D Form B · Line-Item Budget Monitoring System.
      </div>
      <script>window.print();</script>
    </body>
    </html>
  `;
}

export default function AuditTrail() {
  const [log, setLog] = useState([]);
  const [search, setSearch] = useState('');
  const [action, setAction] = useState('All');
  const [recordType, setRecordType] = useState('All');
  const [dateFilter, setDateFilter] = useState('');
  const [toast, setToast] = useState(null); // { msg }
  const [printOpen, setPrintOpen] = useState(false);
  const [printContent, setPrintContent] = useState(null); // { title, html }

  useEffect(() => {
    getWithFallback(ENDPOINTS.audit, auditLog).then(setLog);
  }, []);

  const actions = ['All', ...new Set(auditLog.map((a) => a.action))];
  const recordTypes = ['All', ...new Set(auditLog.map((a) => a.recordType))];

  const filtered = log.filter((a) => {
    const matchesSearch =
      a.user.toLowerCase().includes(search.toLowerCase()) ||
      a.record.toLowerCase().includes(search.toLowerCase());
    const matchesAction = action === 'All' || a.action === action;
    const matchesRecordType = recordType === 'All' || a.recordType === recordType;
    const matchesDate = dateFilter === '' || a.date === dateFilter;
    return matchesSearch && matchesAction && matchesRecordType && matchesDate;
  });

  function showToast(msg) {
    setToast({ msg });
    setTimeout(() => setToast(null), 3500);
  }

  function handleExportCSV() {
    const filename = `audit_log_${new Date().toISOString().slice(0, 10)}.csv`;
    const headers = ['Timestamp', 'User', 'Action', 'Record', 'Record Type', 'Change', 'IP Address'];
    const rows = filtered.map((a) => ({
      Timestamp: a.ts,
      User: a.user,
      Action: ACTION_LABEL[a.action] || a.action,
      Record: a.record,
      'Record Type': a.recordType,
      Change: a.change,
      'IP Address': a.ip,
    }));
    showToast(`Exporting audit log to CSV…`);
    setTimeout(() => downloadCSV(toCSV(rows, headers), filename), 100);
  }

  function handlePrintPDF() {
    const title = 'Audit Trail Log';
    const subtitle = 'Immutable log of every financial action · MSU–Main Campus · MOST-BARMM';
    const meta = [
      { label: 'Total entries', value: filtered.length.toString() },
      { label: 'Date range', value: 'All records' },
      { label: 'Filtered by', value: (search || action !== 'All' || recordType !== 'All' || dateFilter) ? 'Yes' : 'No' },
    ];
    const html = buildPrintHTML(title, subtitle, filtered, meta);
    setPrintContent({ title, html });
    setPrintOpen(true);
  }

  return (
    <>
      <div className="page-head">
        <div>
          <h2>Audit trail</h2>
          <p>Immutable log of every financial action. Entries cannot be edited or deleted.</p>
        </div>
        <div className="actions" style={{ display: 'flex', gap: 8 }}>
          <button className="btn" onClick={handleExportCSV}>Export log</button>
          <button className="btn primary" onClick={handlePrintPDF}>Print / PDF</button>
        </div>
      </div>

      {/* Toast */}
      {toast && (
        <div className="banner toast" style={{ boxShadow: '0 4px 12px rgba(34,29,26,.18)' }}>
          {toast.msg}
        </div>
      )}

      <div className="filter-row">
        <input
          type="search"
          placeholder="Search user or record"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <select value={action} onChange={(e) => setAction(e.target.value)}>
          {actions.map((a) => (
            <option key={a} value={a}>{a === 'All' ? 'All actions' : ACTION_LABEL[a] || a[0].toUpperCase() + a.slice(1)}</option>
          ))}
        </select>
        <select value={recordType} onChange={(e) => setRecordType(e.target.value)}>
          {recordTypes.map((rt) => (
            <option key={rt} value={rt}>{rt === 'All' ? 'All record types' : rt}</option>
          ))}
        </select>
        <input type="date" value={dateFilter} onChange={(e) => setDateFilter(e.target.value)} />
      </div>

      <div className="panel wide-scroll">
        <table>
          <thead>
            <tr>
              <th>Timestamp</th>
              <th>User</th>
              <th>Action</th>
              <th>Record</th>
              <th>Record Type</th>
              <th>Change</th>
              <th>IP address</th>
            </tr>
          </thead>
          <tbody>
            {filtered.map((a) => (
              <tr key={a.id}>
                <td className="num">{a.ts}</td>
                <td>{a.user}</td>
                <td>
                  <Chip tone={ACTION_TONE[a.action] || 'neutral'}>{ACTION_LABEL[a.action] || a.action}</Chip>
                </td>
                <td>{a.record}</td>
                <td>{a.recordType}</td>
                <td>{a.change}</td>
                <td className="num">{a.ip}</td>
              </tr>
            ))}
            {filtered.length === 0 && (
              <tr>
                <td colSpan={7} style={{ textAlign: 'center', color: 'var(--muted)', padding: 24 }}>
                  No audit log entries match these filters.
                </td>
              </tr>
            )}
          </tbody>
        </table>
        <div style={{ padding: '10px 16px', display: 'flex', justifyContent: 'flex-end', fontSize: '11.5px', color: 'var(--muted)' }}>
          {filtered.length} of {log.length} entr{log.length === 1 ? 'y' : 'ies'} displayed
        </div>
      </div>

      {/* Print / PDF modal */}
      {printOpen && printContent && (
        <div className="modal-overlay" onClick={() => setPrintOpen(false)} role="dialog" aria-modal="true" aria-label="Print audit log">
          <div className="modal-panel" style={{ maxWidth: '820px', maxHeight: '95vh', display: 'flex', flexDirection: 'column' }} onClick={(e) => e.stopPropagation()}>
            <div className="modal-head">
              <h3>Print / PDF — {printContent.title}</h3>
              <button type="button" className="modal-close" onClick={() => setPrintOpen(false)} aria-label="Close">×</button>
            </div>
            <div style={{ flex: 1, overflow: 'auto', padding: '16px 20px', fontSize: '12px', lineHeight: '1.5' }} dangerouslySetInnerHTML={{ __html: printContent.html }} />
            <div style={{ padding: '12px 20px', borderTop: '1px solid var(--line)', display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
              <button type="button" className="btn" onClick={() => setPrintOpen(false)}>Close</button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
