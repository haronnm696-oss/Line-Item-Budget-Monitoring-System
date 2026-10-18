import { CURRENT_PROJECT, libCategoriesPS, libCategoriesMOOE, allProjectsForProgram } from '../../data/mockData';
import StatCard from '../../components/StatCard';
import Panel from '../../components/Panel';

function formatMoney(amount) {
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 2,
  }).format(amount);
}

function pct(part, whole) {
  if (!whole) return 0;
  return Math.round((part / whole) * 100);
}

export default function ProgramLeaderDashboard() {

  const psAllocated = libCategoriesPS.reduce((sum, c) => sum + c.allocated, 0);
  const psSpent = libCategoriesPS.reduce((sum, c) => sum + c.spent, 0);
  const mooeAllocated = libCategoriesMOOE.reduce((sum, c) => sum + c.allocated, 0);
  const mooeSpent = libCategoriesMOOE.reduce((sum, c) => sum + c.spent, 0);
  const totalAllocated = psAllocated + mooeAllocated;
  const totalSpent = psSpent + mooeSpent;
  const remaining = totalAllocated - totalSpent;

  return (
    <div className="dashboard program-leader-dashboard" style={{ padding: '24px 26px 60px', maxWidth: '1180px', width: '100%' }}>
      {/* ---------- Header & Metadata ---------- */}
      <div className="panel" style={{ marginBottom: 24 }}>
        <div className="panel-head" style={{ padding: '16px 20px' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 8 }}>
            <div className="seal" style={{ width: 40, height: 40, fontSize: 14 }} />
            <div>
              <div style={{ fontSize: 11, fontWeight: 600, color: '#6E655E', textTransform: 'uppercase', letterSpacing: '.05em' }}>
                MOST R&D Form B · Line-Item Budget
              </div>
              <h2 style={{ fontSize: 20, fontWeight: 700, margin: '2px 0 0', color: '#221D1A' }}>
                {CURRENT_PROJECT.title}
              </h2>
            </div>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 10, fontSize: 13, color: '#221D1A' }}>
            <div><b>Project Code:</b> {CURRENT_PROJECT.code}</div>
            <div><b>Implementing Agency:</b> {CURRENT_PROJECT.implementing_agency}</div>
            <div><b>Monitoring Agency:</b> {CURRENT_PROJECT.funding_agency}</div>
            <div><b>Project Leader:</b> {CURRENT_PROJECT.project_leader}</div>
            <div><b>Project Period:</b> {CURRENT_PROJECT.period}</div>
            <div><b>Status:</b>
              <span style={{ textTransform: 'uppercase', fontSize: 11.5, fontWeight: 600, color: '#2F7D5B' }}>
                {CURRENT_PROJECT.status}
              </span>
            </div>
          </div>
        </div>
      </div>

      {/* ---------- Dual-Funding Structure ---------- */}
      <div className="panel" style={{ marginBottom: 24 }}>
        <div className="panel-head" style={{ padding: '13px 16px' }}>
          <h3 style={{ fontSize: 14 }}>Budget by Funding Source</h3>
        </div>
        <div style={{ padding: '16px 20px 20px', display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 16, alignItems: 'start' }}>
          <div style={{ border: '1px solid #CFC9BC', borderRadius: 8, padding: 16, background: '#F7F6F2' }}>
            <div style={{ fontSize: 11.5, color: '#6E655E', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '.05em', marginBottom: 8 }}>
              Implementing Agency Counterpart
            </div>
            <div style={{ fontSize: 26, fontWeight: 800, color: '#221D1A', marginBottom: 4 }}>
              {formatMoney(CURRENT_PROJECT.counterpart_budget)}
            </div>
            <div style={{ fontSize: 12, color: '#6E655E' }}>MSU-Marawi · counterpart share</div>
          </div>
          <div style={{ border: '1px solid #CFC9BC', borderRadius: 8, padding: 16, background: '#F7F6F2' }}>
            <div style={{ fontSize: 11.5, color: '#6E655E', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '.05em', marginBottom: 8 }}>
              Funding Agency Allocation
            </div>
            <div style={{ fontSize: 26, fontWeight: 800, color: '#221D1A', marginBottom: 4 }}>
              {formatMoney(CURRENT_PROJECT.funding_budget)}
            </div>
            <div style={{ fontSize: 12, color: '#6E655E' }}>MOST-BARMM · grant funding</div>
          </div>
          <div style={{ gridColumn: '1 / -1', border: '2px solid #5E1B25', borderRadius: 8, padding: 14, background: '#F7F6F2', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <div>
              <div style={{ fontSize: 11.5, color: '#6E655E', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '.05em', marginBottom: 4 }}>
                Overall Grand Total
              </div>
              <div style={{ fontSize: 22, fontWeight: 800, color: '#221D1A' }}>
                {formatMoney(CURRENT_PROJECT.total_budget)}
              </div>
            </div>
            <div style={{ fontSize: 11.5, color: '#6E655E', textAlign: 'right' }}>
              PS + MOOE combined<br />
              {pct(totalSpent, totalAllocated)}% utilized
            </div>
          </div>
        </div>
      </div>

      {/* ---------- Summary Widgets ---------- */}
      <div className="grid c4" style={{ marginBottom: 24, gap: 14 }}>
        <StatCard label="Total MOST Funding" value={formatMoney(CURRENT_PROJECT.funding_budget)} sub="MOST-BARMM grant" />
        <StatCard label="MSU Counterpart Funding" value={formatMoney(CURRENT_PROJECT.counterpart_budget)} sub="MSU-Marawi share" />
        <StatCard label="Total Disbursed" value={formatMoney(totalSpent)} sub={`${pct(totalSpent, totalAllocated)}% of total budget`} />
        <StatCard label="Remaining Balance" value={formatMoney(remaining)} sub={`${pct(remaining, totalAllocated)}% unspent`} />
      </div>

      {/* ---------- PS vs MOOE Utilization ---------- */}
      <div className="grid c2-1" style={{ marginBottom: 24, gap: 14 }}>
        <Panel title="Budget Utilization: PS vs MOOE" hint="Percent of allocated amount disbursed per category">
          <div style={{ padding: '18px 20px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 8, fontSize: 12, color: '#6E655E' }}>
              <span>Personnel Services (PS)</span>
              <span>{formatMoney(psSpent)} / {formatMoney(psAllocated)}</span>
            </div>
            <div className="util" style={{ marginBottom: 14 }}>
              <div className="track" />
              <div className="fill" style={{ width: `${pct(psSpent, psAllocated)}%`, background: '#2F7D5B' }} />
            </div>
            <div style={{ fontSize: 11, color: '#2F7D5B', fontWeight: 600 }}>
              {pct(psSpent, psAllocated)}% utilized
            </div>

            <div style={{ marginTop: 18, display: 'flex', justifyContent: 'space-between', fontSize: 12, color: '#6E655E' }}>
              <span>Maintenance & Other Operating Expenses (MOOE)</span>
              <span>{formatMoney(mooeSpent)} / {formatMoney(mooeAllocated)}</span>
            </div>
            <div className="util" style={{ marginBottom: 8 }}>
              <div className="track" />
              <div className="fill" style={{ width: `${pct(mooeSpent, mooeAllocated)}%`, background: '#9A6B10' }} />
            </div>
            <div style={{ fontSize: 11, color: '#9A6B10', fontWeight: 600 }}>
              {pct(mooeSpent, mooeAllocated)}% utilized
            </div>
          </div>
        </Panel>

        <Panel title="Program Projects" hint="All projects under this program leader">
          <div style={{ padding: '18px 20px', display: 'flex', flexDirection: 'column', gap: 10 }}>
            {allProjectsForProgram.map((p) => (
              <div key={p.name} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: 12.5, borderBottom: '1px solid #E4E0D7', paddingBottom: 6 }}>
                <div>
                  <div style={{ fontWeight: 600, color: '#221D1A' }}>{p.name}</div>
                  <div style={{ fontSize: 11, color: '#6E655E' }}>{p.leader}</div>
                </div>
                <div style={{ textAlign: 'right', fontSize: 12, fontWeight: 600, color: '#221D1A' }}>
                  {formatMoney(p.budget)}
                </div>
              </div>
            ))}
          </div>
        </Panel>
      </div>

      {/* ---------- Line-Item Tables: PS & MOOE ---------- */}
      <div className="grid c2-1" style={{ gap: 14 }}>
        <Panel title="I. Personnel Services (PS)" hint="Direct &amp; Indirect Honoraria">
          <div style={{ padding: '0 20px 20px' }}>
            <table style={{ width: '100%', fontSize: 12.5, borderCollapse: 'collapse' }}>
              <thead>
                <tr style={{ textAlign: 'left', borderBottom: '1px solid #CFC9BC' }}>
                  <th style={{ padding: '9px 10px', color: '#6E655E', fontSize: 11, fontWeight: 600, textAlign: 'left' }}>Line Item</th>
                  <th style={{ padding: '9px 10px', color: '#6E655E', fontSize: 11, fontWeight: 600, textAlign: 'right' }}>Allocated</th>
                  <th style={{ padding: '9px 10px', color: '#6E655E', fontSize: 11, fontWeight: 600, textAlign: 'right' }}>Disbursed</th>
                  <th style={{ padding: '9px 10px', color: '#6E655E', fontSize: 11, fontWeight: 600, textAlign: 'right' }}>Util.</th>
                </tr>
              </thead>
              <tbody>
                {libCategoriesPS.map((c) => (
                  <tr key={c.name} style={{ borderBottom: '1px solid #E4E0D7' }}>
                    <td style={{ padding: '8px 10px', color: '#221D1A' }}>{c.name}</td>
                    <td style={{ padding: '8px 10px', color: '#221D1A', textAlign: 'right' }}>{formatMoney(c.allocated)}</td>
                    <td style={{ padding: '8px 10px', color: '#221D1A', textAlign: 'right' }}>{formatMoney(c.spent)}</td>
                    <td style={{ padding: '8px 10px', color: '#2F7D5B', fontWeight: 600, textAlign: 'right' }}>{pct(c.spent, c.allocated)}%</td>
                  </tr>
                ))}
                <tr style={{ borderTop: '2px solid #221D1A', fontWeight: 700 }}>
                  <td style={{ padding: '10px', color: '#221D1A' }}>PS Subtotal</td>
                  <td style={{ padding: '10px', color: '#221D1A', textAlign: 'right' }}>{formatMoney(psAllocated)}</td>
                  <td style={{ padding: '10px', color: '#221D1A', textAlign: 'right' }}>{formatMoney(psSpent)}</td>
                  <td style={{ padding: '10px', color: '#2F7D5B', textAlign: 'right' }}>{pct(psSpent, psAllocated)}%</td>
                </tr>
              </tbody>
            </table>
          </div>
        </Panel>

        <Panel title="II. Maintenance & Other Operating Expenses (MOOE)" hint="Traveling, Supplies/IoT/Laptops, Communication, Professional Services, Cloud/Server Rent, Utilities">
          <div style={{ padding: '0 20px 20px' }}>
            <table style={{ width: '100%', fontSize: 12.5, borderCollapse: 'collapse' }}>
              <thead>
                <tr style={{ textAlign: 'left', borderBottom: '1px solid #CFC9BC' }}>
                  <th style={{ padding: '9px 10px', color: '#6E655E', fontSize: 11, fontWeight: 600, textAlign: 'left' }}>Line Item</th>
                  <th style={{ padding: '9px 10px', color: '#6E655E', fontSize: 11, fontWeight: 600, textAlign: 'right' }}>Allocated</th>
                  <th style={{ padding: '9px 10px', color: '#6E655E', fontSize: 11, fontWeight: 600, textAlign: 'right' }}>Disbursed</th>
                  <th style={{ padding: '9px 10px', color: '#6E655E', fontSize: 11, fontWeight: 600, textAlign: 'right' }}>Util.</th>
                </tr>
              </thead>
              <tbody>
                {libCategoriesMOOE.map((c) => (
                  <tr key={c.name} style={{ borderBottom: '1px solid #E4E0D7' }}>
                    <td style={{ padding: '8px 10px', color: '#221D1A' }}>{c.name}</td>
                    <td style={{ padding: '8px 10px', color: '#221D1A', textAlign: 'right' }}>{formatMoney(c.allocated)}</td>
                    <td style={{ padding: '8px 10px', color: '#221D1A', textAlign: 'right' }}>{formatMoney(c.spent)}</td>
                    <td style={{ padding: '8px 10px', color: '#9A6B10', fontWeight: 600, textAlign: 'right' }}>{pct(c.spent, c.allocated)}%</td>
                  </tr>
                ))}
                <tr style={{ borderTop: '2px solid #221D1A', fontWeight: 700 }}>
                  <td style={{ padding: '10px', color: '#221D1A' }}>MOOE Subtotal</td>
                  <td style={{ padding: '10px', color: '#221D1A', textAlign: 'right' }}>{formatMoney(mooeAllocated)}</td>
                  <td style={{ padding: '10px', color: '#221D1A', textAlign: 'right' }}>{formatMoney(mooeSpent)}</td>
                  <td style={{ padding: '10px', color: '#9A6B10', textAlign: 'right' }}>{pct(mooeSpent, mooeAllocated)}%</td>
                </tr>
              </tbody>
            </table>
          </div>
        </Panel>
      </div>
    </div>
  );
}
