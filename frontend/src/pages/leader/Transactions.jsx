import { useState } from 'react';
import { DATE_BOUNDS, inDateRange } from '../../lib/dateRange';
import { useSearchParams } from 'react-router-dom';
import { ENDPOINTS } from '../../api/endpoints';
import { useApiData } from '../../lib/useApiData';
import { useAssignedProject } from '../../lib/useAssignedProject';
import { notAssignedText } from '../../lib/myProjects';
import LoadState from '../../components/LoadState';
import ProjectLine from '../../components/ProjectLine';
import RecordExpenseDialog from '../../components/leader/RecordExpenseDialog';
import { useAuth } from '../../context/AuthContext';
import { normalizeRole } from '../../lib/roles';
import { isReadOnlyRole, transactionsTitle } from '../../lib/pageHead';
import PageTitle from '../../components/PageTitle';
import Tip from '../../components/Tip';
import DocumentList from '../../components/leader/DocumentList';
import DetailModal from '../../components/DetailModal';
import ProgramPill from '../../components/program/ProgramPill';
import { useVerifyActions } from '../../components/finance/useVerifyActions';
import { peso } from '../../components/money';
import { formatDate } from '../../lib/datetime';

const FILTERS = ['from', 'to', 'line_item', 'category', 'receipt'];

/**
 * Transactions: what Total spent adds up, newest first, 25 per page, with
 * date range, line item and category filters in the URL. Spent = verified
 * expenses and verified payroll (one payroll row per batch and line item,
 * `kind: 'payroll'`; its documents are the batch's payroll files).
 * "Missing receipt" (?receipt=missing) keeps only verified expenses without
 * a receipt: the Finance Overview's "Missing receipts" KPI opens it, and
 * Finance/Admin can ask for the receipt (Notify RA) from each row.
 */
export default function Transactions() {
  const picker = useAssignedProject();
  // Only the Project Leader records here. They lead one project and cannot
  // switch; Finance/Admin and Researchers switch from the project line.
  const roleCode = normalizeRole(useAuth().user);
  const isLeader = roleCode === 'PL';
  const isFinance = roleCode === 'FIN';
  // Researchers read only and never open payroll files (pay per person;
  // the API refuses them too).
  const readOnly = isReadOnlyRole(roleCode);
  const [searchParams, setSearchParams] = useSearchParams();
  const [docsFor, setDocsFor] = useState(null);
  const [recording, setRecording] = useState(false);
  const [feedback, setFeedback] = useState(null); // { tone, text }

  const query = new URLSearchParams();
  FILTERS.forEach((key) => { const v = searchParams.get(key); if (v) query.set(key, v); });
  const page = Math.max(1, Number(searchParams.get('page')) || 1);
  if (page > 1) query.set('page', String(page));
  const url = picker.projectId ? `${ENDPOINTS.transactions(picker.projectId)}${query.toString() ? `?${query}` : ''}` : null;
  const result = useApiData(url);
  const actions = useVerifyActions({ projectId: picker.projectId, onChanged: result.retry });
  const missingOnly = searchParams.get('receipt') === 'missing';

  const rows = Array.isArray(result.data?.transactions) ? result.data.transactions : [];
  const meta = result.data?.meta;
  const [lineItems, setLineItems] = useState([]);
  // Keep the last line-item options while a filtered page loads.
  const freshItems = result.data?.line_items;
  if (Array.isArray(freshItems) && freshItems !== lineItems) setLineItems(freshItems);

  const hasFilters = FILTERS.some((key) => searchParams.get(key));

  function update(changes) {
    const next = new URLSearchParams(searchParams);
    Object.entries(changes).forEach(([key, value]) => (value ? next.set(key, value) : next.delete(key)));
    if (!('page' in changes)) next.delete('page');
    setSearchParams(next, { replace: true });
  }

  const validationError = result.status === 'error' && result.error?.status === 422
    ? Object.values(result.error.fieldErrors ?? {}).flat()[0]
    : null;

  return (
    <div className="ld-page">
      <div className="pl-head">
        <div>
          <PageTitle title={transactionsTitle(roleCode)} readOnly={readOnly} />
          <ProjectLine picker={picker} />
        </div>
        {/* With no project led, the button stays hidden; the page shows notAssignedText instead. */}
        {isLeader && picker.projectId && (
          <div className="pl-head-actions">
            <button type="button" className="program-btn primary" onClick={() => { setFeedback(null); setRecording(true); }}>
              Record transaction
            </button>
          </div>
        )}
      </div>

      {(feedback ?? actions.feedback) && (
        <div className={`program-banner ${(feedback ?? actions.feedback).tone}`} role="status" aria-live="polite">{(feedback ?? actions.feedback).text}</div>
      )}

      <LoadState state={picker.state} emptyText={notAssignedText(picker.role)} isEmpty={picker.state.status === 'ready' && !picker.project}>
        <div className="program-filter-row ld-controls">
          <div className="program-field">
            <label htmlFor="tx-from">From</label>
            <input id="tx-from" type="date" className="program-input" {...DATE_BOUNDS} value={searchParams.get('from') ?? ''} onChange={(e) => { if (inDateRange(e.target.value)) update({ from: e.target.value }); }} />
          </div>
          <div className="program-field">
            <label htmlFor="tx-to">To</label>
            <input id="tx-to" type="date" className="program-input" {...DATE_BOUNDS} value={searchParams.get('to') ?? ''} onChange={(e) => { if (inDateRange(e.target.value)) update({ to: e.target.value }); }} />
          </div>
          <div className="program-field">
            <label htmlFor="tx-category">Category</label>
            <select id="tx-category" className="program-select" value={searchParams.get('category') ?? ''} onChange={(e) => update({ category: e.target.value, line_item: '' })}>
              <option value="">PS and MOOE</option>
              <option value="PS">PS</option>
              <option value="MOOE">MOOE</option>
            </select>
          </div>
          <div className="program-field">
            <label htmlFor="tx-line">Line item</label>
            <select id="tx-line" className="program-select" value={searchParams.get('line_item') ?? ''} onChange={(e) => update({ line_item: e.target.value })}>
              <option value="">All line items</option>
              {lineItems
                .filter((item) => !searchParams.get('category') || item.type === searchParams.get('category'))
                .map((item) => <option key={item.id} value={item.id}>{item.type} · {item.name}</option>)}
            </select>
          </div>
          <div className="program-field">
            <label htmlFor="tx-receipt">Receipt</label>
            <select id="tx-receipt" className="program-select" value={missingOnly ? 'missing' : ''} onChange={(e) => update({ receipt: e.target.value })}>
              <option value="">All transactions</option>
              <option value="missing">Missing receipt</option>
            </select>
          </div>
          {hasFilters && (
            <button type="button" className="program-btn" onClick={() => update({ from: '', to: '', line_item: '', category: '', receipt: '' })}>Clear filters</button>
          )}
        </div>

        {validationError ? (
          <div className="program-banner error" role="alert">{validationError}</div>
        ) : (
          <LoadState state={result} loadingText="Loading transactions…" emptyText={missingOnly ? '✓ Every verified expense matching these filters has a receipt.' : (hasFilters ? 'No verified expenses or payroll match these filters.' : 'No verified expenses or payroll yet.')} isEmpty={rows.length === 0}>
            <section className="program-card">
              <div className="program-card-head">
                <div>
                  <div className="program-card-title">{meta?.total ?? 0} transaction{meta?.total === 1 ? '' : 's'}</div>
                  <div className="program-card-sub">Total {peso(meta?.amount ?? 0)}</div>
                </div>
              </div>
              <div className="program-scroll">
                <table className="program-table ld-table">
                  <thead>
                    <tr>
                      <th scope="col">Date</th>
                      <th scope="col">Description</th>
                      <th scope="col">Line item</th>
                      <th scope="col" className="num">Amount</th>
                      <th scope="col">Documents</th>
                    </tr>
                  </thead>
                  <tbody>
                    {rows.map((row) => (
                      <tr key={`${row.kind ?? 'expense'}-${row.id}`}>
                        <td>{formatDate(row.date)}</td>
                        <td>
                          <div className="project-name">{row.kind === 'payroll' ? <><span className="ra-type">Payroll</span> {row.description}</> : row.description}</div>
                          <div className="project-code">
                            {[row.payee, row.reference_no && `Ref. ${row.reference_no}`, row.verified_by && `Verified by ${row.verified_by}`].filter(Boolean).join(' · ')}
                          </div>
                        </td>
                        <td>{row.line_item}<div className="project-code">{row.category ?? ''}</div></td>
                        <td className="num">{peso(row.amount)}</td>
                        <td>
                          {readOnly && row.kind === 'payroll' ? (
                            <Tip text="Payroll files are not shown to Researchers.">—</Tip>
                          ) : (
                            <button type="button" className="ld-link" onClick={() => setDocsFor(row)}>
                              {row.documents_count > 0 ? `${row.documents_count} document${row.documents_count === 1 ? '' : 's'}` : 'View documents'}
                            </button>
                          )}
                          {row.kind !== 'payroll' && row.receipts_count === 0 && (
                            <>
                              <div className="project-code"><span aria-hidden="true">!</span> No receipt</div>
                              {isFinance && (
                                <button type="button" className="program-btn sm" disabled={actions.busyKey === `notify-${row.id}`} onClick={() => actions.notifyRa(row)}>
                                  {actions.busyKey === `notify-${row.id}` ? 'Sending…' : 'Notify RA'}
                                </button>
                              )}
                            </>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {meta && meta.last_page > 1 && (
                <nav className="ld-pager" aria-label="Pages">
                  <button type="button" className="program-btn sm" disabled={meta.page <= 1} onClick={() => update({ page: String(meta.page - 1) })}>Previous</button>
                  <span>Page {meta.page} of {meta.last_page}</span>
                  <button type="button" className="program-btn sm" disabled={meta.page >= meta.last_page} onClick={() => update({ page: String(meta.page + 1) })}>Next</button>
                </nav>
              )}
            </section>
          </LoadState>
        )}
      </LoadState>

      {isLeader && recording && picker.projectId && (
        <RecordExpenseDialog
          projectId={picker.projectId}
          onClose={() => setRecording(false)}
          onDone={(text) => {
            setRecording(false);
            setFeedback({ tone: 'ok', text });
            result.retry();
          }}
        />
      )}

      {docsFor && (
        <DetailModal
          titleId="tx-docs-title"
          title={docsFor.description}
          eyebrow={`${docsFor.kind === 'payroll' ? 'Payroll' : 'Expense'} · ${formatDate(docsFor.date)}`}
          icon={docsFor.kind === 'payroll' ? '₱' : '▤'}
          chips={<ProgramPill value="Verified" />}
          amount={docsFor.amount}
          amountCaption="Amount"
          onClose={() => setDocsFor(null)}
        >
          <dl className="ld-fields">
            <div><dt>Date</dt><dd>{formatDate(docsFor.date)}</dd></div>
            <div><dt>Line item</dt><dd>{docsFor.line_item}{docsFor.category ? ` (${docsFor.category})` : ''}</dd></div>
            <div><dt>Verified by</dt><dd>{docsFor.verified_by}</dd></div>
            {docsFor.payee && <div><dt>Payee</dt><dd>{docsFor.payee}</dd></div>}
            {docsFor.reference_no && <div><dt>Reference</dt><dd>{docsFor.reference_no}</dd></div>}
          </dl>
          <h3 className="ld-panel-sub">Documents</h3>
          {docsFor.kind === 'payroll'
            ? (docsFor.batch_id ? <DocumentList type="payroll" id={docsFor.batch_id} /> : <p className="ld-muted">No payroll files.</p>)
            : <DocumentList type="expense" id={docsFor.id} />}
        </DetailModal>
      )}
    </div>
  );
}
