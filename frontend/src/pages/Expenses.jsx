import { useState } from 'react';
import Chip, { statusTone } from '../components/Chip';
import LoadState from '../components/LoadState';
import ProjectPicker from '../components/ProjectPicker';
import { peso } from '../components/money';
import { apiWrite } from '../api/http';
import { ENDPOINTS } from '../api/endpoints';
import { useAuth } from '../context/AuthContext';
import { SAVE_ERROR } from '../lib/apiResult';
import { useApiData } from '../lib/useApiData';
import { useAssignedProject } from '../lib/useAssignedProject';

const EMPTY_FORM = { lib_category_id: '', description: '', amount: '', date: '', reference: '' };

export default function Expenses() {
  const { user } = useAuth();
  const picker = useAssignedProject();
  // The project's detail carries its line items and expense rows.
  const detail = useApiData(picker.projectId ? ENDPOINTS.project(picker.projectId) : null);

  const [search, setSearch] = useState('');
  const [category, setCategory] = useState('All');
  const [status, setStatus] = useState('All');
  const [form, setForm] = useState(EMPTY_FORM);
  const [submitting, setSubmitting] = useState(false);
  const [notice, setNotice] = useState(null);

  const data = detail.data;
  const list = Array.isArray(data?.expenses) ? data.expenses : [];
  const lineItems = [...(data?.categories?.ps ?? []), ...(data?.categories?.mooe ?? [])].map((item) => ({
    id: item.id,
    name: item.name,
    remaining: (Number(item.allocated) || 0) - (Number(item.spent) || 0),
  }));
  const categories = ['All', ...new Set(list.map((e) => e.category).filter(Boolean))];
  const statuses = ['All', 'Pending', 'Verified', 'Rejected'];

  const filtered = list.filter((e) =>
    (category === 'All' || e.category === category) &&
    (status === 'All' || e.status === status) &&
    (`${e.desc ?? ''} ${e.ref ?? ''}`.toLowerCase().includes(search.toLowerCase()))
  );

  const selectedLineItem = lineItems.find((l) => String(l.id) === String(form.lib_category_id)) ?? lineItems[0];
  const amountNum = Number(form.amount) || 0;
  const wouldExceed = selectedLineItem && amountNum > selectedLineItem.remaining;

  async function submit(event) {
    event.preventDefault();
    if (!form.description || !amountNum || !form.date || !selectedLineItem) return;
    setSubmitting(true);
    setNotice(null);
    try {
      // Saved only if the server confirms; a failure never adds a row.
      await apiWrite('post', ENDPOINTS.expenses(picker.projectId), {
        lib_category_id: selectedLineItem.id,
        description: form.description,
        amount: amountNum,
        expense_date: form.date,
        reference_no: form.reference || null,
      });
      setForm(EMPTY_FORM);
      setNotice({ tone: 'ok', text: 'Expense submitted for verification.' });
      detail.retry();
    } catch (error) {
      setNotice({ tone: 'over', text: error.message || SAVE_ERROR });
    } finally {
      setSubmitting(false);
    }
  }

  const canRecord = user.role === 'RA';

  return (
    <>
      <div className="page-head">
        <div>
          <h2>Expenses</h2>
          <p>{picker.project ? `${picker.project.title} · ` : ''}Recorded charges against MOOE and PS line items</p>
        </div>
        <ProjectPicker picker={picker} id="expenses-project" />
      </div>

      <LoadState state={picker.state} loadingText="Loading your projects…" isEmpty={picker.projects.length === 0} emptyText="You are not assigned to any project yet.">
        <LoadState state={detail} loadingText="Loading expenses…">
          <div className="grid c2-1">
            <div>
              <div className="filter-row">
                <input type="search" placeholder="Search description or reference no." value={search} onChange={(e) => setSearch(e.target.value)} />
                <select value={category} onChange={(e) => setCategory(e.target.value)}>
                  {categories.map((c) => <option key={c} value={c}>{c === 'All' ? 'All line items' : c}</option>)}
                </select>
                <select value={status} onChange={(e) => setStatus(e.target.value)}>
                  {statuses.map((s) => <option key={s} value={s}>{s === 'All' ? 'All statuses' : s}</option>)}
                </select>
              </div>
              <div className="panel wide-scroll">
                <table>
                  <thead><tr><th>Date</th><th>Ref. no.</th><th>Description</th><th>Line item</th><th className="r">Amount</th><th>Status</th></tr></thead>
                  <tbody>
                    {filtered.map((e) => (
                      <tr key={e.id}>
                        <td className="num">{e.date}</td>
                        <td className="num">{e.ref || '—'}</td>
                        <td>{e.desc}</td>
                        <td>{e.category || '—'}</td>
                        <td className="r num mono-b">{peso(e.amount)}</td>
                        <td><Chip tone={statusTone(e.status)}>{e.status}</Chip></td>
                      </tr>
                    ))}
                    {filtered.length === 0 && (
                      <tr><td colSpan={6} style={{ textAlign: 'center', color: 'var(--muted)', padding: 24 }}>
                        {list.length === 0 ? 'No expenses recorded for this project yet.' : 'No expenses match these filters.'}
                      </td></tr>
                    )}
                  </tbody>
                </table>
              </div>
            </div>

            {canRecord && (
              <div className="panel">
                <div className="panel-head"><h3>Record expense</h3><span className="hint">Research Assistant</span></div>
                <form style={{ padding: 16 }} onSubmit={submit}>
                  <div className="field">
                    <label htmlFor="expense-line-item">Line item</label>
                    <select id="expense-line-item" value={selectedLineItem?.id ?? ''} onChange={(e) => setForm({ ...form, lib_category_id: e.target.value })}>
                      {lineItems.map((l) => <option key={l.id} value={l.id}>{l.name} — {peso(l.remaining)} remaining</option>)}
                    </select>
                  </div>
                  <div className="field">
                    <label htmlFor="expense-description">Description</label>
                    <input id="expense-description" type="text" placeholder="What was this expense for?" value={form.description}
                      onChange={(e) => setForm({ ...form, description: e.target.value })} />
                  </div>
                  <div className="grid c2">
                    <div className="field">
                      <label htmlFor="expense-amount">Amount (₱)</label>
                      <input id="expense-amount" type="text" inputMode="decimal" placeholder="0.00" className="num" value={form.amount}
                        onChange={(e) => setForm({ ...form, amount: e.target.value.replace(/[^0-9.]/g, '') })} />
                    </div>
                    <div className="field">
                      <label htmlFor="expense-date">Expense date</label>
                      <input id="expense-date" type="date" value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} />
                    </div>
                  </div>
                  <div className="field">
                    <label htmlFor="expense-reference">Reference no. (OR / voucher)</label>
                    <input id="expense-reference" type="text" placeholder="e.g. OR-2842" value={form.reference}
                      onChange={(e) => setForm({ ...form, reference: e.target.value })} />
                  </div>
                  {wouldExceed && (
                    <div className="banner over">
                      This amount exceeds the {peso(selectedLineItem.remaining)} remaining on this line item. It can still be submitted, but will need budget-revision sign-off.
                    </div>
                  )}
                  {notice && <div className={`banner ${notice.tone}`} role={notice.tone === 'over' ? 'alert' : 'status'}>{notice.text}</div>}
                  <button className="btn primary" style={{ width: '100%' }} disabled={submitting || lineItems.length === 0}>
                    {submitting && <span className="spin" />}Submit for verification
                  </button>
                </form>
              </div>
            )}
          </div>
        </LoadState>
      </LoadState>
    </>
  );
}
