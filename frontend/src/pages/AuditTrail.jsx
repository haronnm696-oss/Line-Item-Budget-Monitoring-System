import { useState } from 'react';
import { DATE_BOUNDS, inDateRange } from '../lib/dateRange';
import Chip from '../components/Chip';
import LoadState from '../components/LoadState';
import { ENDPOINTS } from '../api/endpoints';
import { useApiData } from '../lib/useApiData';
import { useAuth } from '../context/AuthContext';
import { fetchProgramLeaderName } from '../api/reports';
import { printHtmlDocument } from '../lib/download';
import { auditReportHtml } from '../lib/programReports';
import { normalizeRole, ROLE_MAP } from '../lib/roles';

const ACTION_TONE = {
  created: 'neutral',
  updated: 'neutral',
  requested: 'neutral',
  budget_changed: 'neutral',
  verified: 'ok',
  approved: 'ok',
  rejected: 'over',
};
const ACTION_LABEL = {
  created: 'Created',
  updated: 'Updated',
  requested: 'Requested',
  budget_changed: 'Budget changed',
  verified: 'Verified',
  approved: 'Approved',
  rejected: 'Rejected',
};

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

export default function AuditTrail() {
  const { user } = useAuth();
  // Entries of the user's assigned projects only (the API scopes the feed).
  const state = useApiData(ENDPOINTS.audit);
  const log = Array.isArray(state.data) ? state.data : [];
  const [search, setSearch] = useState('');
  const [action, setAction] = useState('All');
  const [recordType, setRecordType] = useState('All');
  const [dateFilter, setDateFilter] = useState('');
  const [toast, setToast] = useState(null); // { msg }

  const actions = ['All', ...new Set(log.map((a) => a.action))];
  const recordTypes = ['All', ...new Set(log.map((a) => a.recordType))];

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

  // The printed log: the official report layout (lib/programReports.js),
  // the entries shown with the current filters.
  async function handlePrintPDF() {
    const filters = [
      `Action: ${action === 'All' ? 'All actions' : ACTION_LABEL[action] || action}`,
      `Record type: ${recordType === 'All' ? 'All record types' : recordType}`,
      `Date: ${dateFilter || 'All dates'}`,
      ...(search ? [`Search: "${search}"`] : []),
    ].join(' · ');
    const programLeader = await fetchProgramLeaderName();
    printHtmlDocument(auditReportHtml({
      entries: filtered,
      actionLabel: (value) => ACTION_LABEL[value] || value,
      filters,
      preparedBy: { name: user?.name, role: ROLE_MAP[normalizeRole(user)]?.label },
      programLeader,
    }), { title: 'Audit trail' });
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
        <input type="date" {...DATE_BOUNDS} value={dateFilter} onChange={(e) => { if (inDateRange(e.target.value)) setDateFilter(e.target.value); }} />
      </div>

      <LoadState state={state} loadingText="Loading audit entries…">
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
      </LoadState>
    </>
  );
}
