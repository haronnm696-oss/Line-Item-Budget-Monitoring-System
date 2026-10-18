import { useEffect, useState } from 'react';
import Chip, { statusTone } from '../components/Chip';
import { peso } from '../components/money';
import { getWithFallback, mutateWithFallback } from '../api/http';
import { ENDPOINTS } from '../api/endpoints';
import { expenses as mockExpenses, libCategoriesMOOE, libCategoriesPS, CURRENT_PROJECT } from '../data/mockData';
import { useAuth } from '../context/AuthContext';

const lineItems = [...libCategoriesPS, ...libCategoriesMOOE].map((c) => ({
  name: c.name,
  remaining: c.allocated - c.spent,
}));

export default function Expenses() {
  const { user } = useAuth();
  const [list, setList] = useState([]);
  const [search, setSearch] = useState('');
  const [category, setCategory] = useState('All');
  const [status, setStatus] = useState('All');

  const [form, setForm] = useState({ category: lineItems[0].name, description: '', amount: '', date: '', reference: '' });
  const [submitting, setSubmitting] = useState(false);
  const [notice, setNotice] = useState(null);

  useEffect(() => {
    getWithFallback(ENDPOINTS.expenses(CURRENT_PROJECT.id), mockExpenses).then(setList);
  }, []);

  const categories = ['All', ...new Set(mockExpenses.map((e) => e.category))];
  const statuses = ['All', 'Pending', 'Verified', 'Rejected'];

  const filtered = list.filter((e) =>
    (category === 'All' || e.category === category) &&
    (status === 'All' || e.status === status) &&
    (e.desc.toLowerCase().includes(search.toLowerCase()) || e.ref.toLowerCase().includes(search.toLowerCase()))
  );

  const selectedLineItem = lineItems.find((l) => l.name === form.category);
  const amountNum = Number(form.amount) || 0;
  const wouldExceed = selectedLineItem && amountNum > selectedLineItem.remaining;

  async function submit(e) {
    e.preventDefault();
    if (!form.description || !amountNum || !form.date) return;
    setSubmitting(true);
    try {
      const newExpense = {
        id: Date.now(),
        date: form.date,
        ref: form.reference || '—',
        desc: form.description,
        category: form.category,
        amount: amountNum,
        status: 'Pending',
      };
      await mutateWithFallback('post', ENDPOINTS.expenses(CURRENT_PROJECT.id), form, newExpense);
      setList((prev) => [newExpense, ...prev]);
      setForm({ category: lineItems[0].name, description: '', amount: '', date: '', reference: '' });
      setNotice({ tone: 'ok', text: 'Expense submitted for verification.' });
    } catch (err) {
      setNotice({ tone: 'over', text: err.message || 'Could not submit expense.' });
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
          <p>SAGIP-Lawa · Recorded charges against MOOE and PS line items</p>
        </div>
        <div className="actions">
          <button className="btn">Export CSV</button>
        </div>
      </div>

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
                    <td className="num">{e.ref}</td>
                    <td>{e.desc}</td>
                    <td>{e.category}</td>
                    <td className="r num mono-b">{peso(e.amount)}</td>
                    <td><Chip tone={statusTone(e.status)}>{e.status}</Chip></td>
                  </tr>
                ))}
                {filtered.length === 0 && (
                  <tr><td colSpan={6} style={{ textAlign: 'center', color: 'var(--muted)', padding: 24 }}>No expenses match these filters.</td></tr>
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
                <label>Line item</label>
                <select value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })}>
                  {lineItems.map((l) => <option key={l.name} value={l.name}>{l.name} — {peso(l.remaining)} remaining</option>)}
                </select>
              </div>
              <div className="field">
                <label>Description</label>
                <input type="text" placeholder="What was this expense for?" value={form.description}
                  onChange={(e) => setForm({ ...form, description: e.target.value })} />
              </div>
              <div className="grid c2">
                <div className="field">
                  <label>Amount (₱)</label>
                  <input type="text" inputMode="decimal" placeholder="0.00" className="num" value={form.amount}
                    onChange={(e) => setForm({ ...form, amount: e.target.value.replace(/[^0-9.]/g, '') })} />
                </div>
                <div className="field">
                  <label>Expense date</label>
                  <input type="date" value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} />
                </div>
              </div>
              <div className="field">
                <label>Reference no. (OR / voucher)</label>
                <input type="text" placeholder="e.g. OR-2842" value={form.reference}
                  onChange={(e) => setForm({ ...form, reference: e.target.value })} />
              </div>
              <div className="field">
                <label>Attachment (receipt / proof)</label>
                <input type="file" />
              </div>
              {wouldExceed && (
                <div className="banner over">
                  This amount exceeds the {peso(selectedLineItem.remaining)} remaining on this line item. It can still be submitted, but will need budget-revision sign-off.
                </div>
              )}
              {!wouldExceed && (
                <div className="banner warn">Amounts above the line item's remaining balance are flagged before submission.</div>
              )}
              {notice && <div className={`banner ${notice.tone}`}>{notice.text}</div>}
              <button className="btn primary" style={{ width: '100%' }} disabled={submitting}>
                {submitting && <span className="spin" />}Submit for verification
              </button>
            </form>
          </div>
        )}
      </div>
    </>
  );
}
