import { useEffect, useState } from 'react';
import { useAuth } from '../../context/AuthContext';
import {
  CURRENT_PROJECT,
  allProjectsForProgram,
  monthlyExpensesChart as monthlyExpenses,
} from '../../data/mockData';
import { buildMockDashboard } from '../../data/dashboardMock';
import {
  fetchProgramDashboard,
  approveApproval,
  rejectApproval,
  downloadReport,
  saveBlob,
} from '../../api/dashboard';
import StatCard from '../../components/StatCard';
import Panel from '../../components/Panel';
import LineChart from '../../components/charts/LineChart';
import DonutChart from '../../components/charts/DonutChart';
import PrintableFormB from '../../components/PrintableFormB';
import TrancheTimeline from '../../components/TrancheTimeline';
import BurnRateCard from '../../components/BurnRateCard';
import QuickApprovals from '../../components/QuickApprovals';
import ComplianceTracker from '../../components/ComplianceTracker';
import ExportShortcuts from '../../components/ExportShortcuts';
import { PrinterIcon } from '../../components/AuthIcons';

function formatMoney(amount) {
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 2,
  }).format(Number(amount) || 0);
}

function pct(part, whole) {
  if (!whole) return 0;
  return Math.round((part / whole) * 100);
}

export default function ProgramLeaderDashboard() {
  const { user } = useAuth();
  const [summary, setSummary] = useState(() => buildMockDashboard());
  const [formBOpen, setFormBOpen] = useState(false);
  const [loading, setLoading] = useState(true);
  const [usingDemo, setUsingDemo] = useState(true);
  const [busyApprovalId, setBusyApprovalId] = useState(null);
  const [feedback, setFeedback] = useState(null);
  const [downloadingReport, setDownloadingReport] = useState(false);

  useEffect(() => {
    let active = true;

    fetchProgramDashboard()
      .then((data) => {
        if (!active || !data) return;
        setSummary(data);
        setUsingDemo(data.source === 'mock');
      })
      .catch(() => {
        if (active) {
          setFeedback({
            tone: 'warn',
            text: 'Live dashboard data is unavailable right now — showing demo values.',
          });
        }
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => {
      active = false;
    };
  }, []);

  const project = summary.project || CURRENT_PROJECT;
  const psCategories = summary.categories?.ps ?? [];
  const mooeCategories = summary.categories?.mooe ?? [];
  const approvals = summary.approvals ?? [];

  const psAllocated = psCategories.reduce((sum, category) => sum + Number(category.allocated || 0), 0);
  const psSpent = psCategories.reduce((sum, category) => sum + Number(category.spent || 0), 0);
  const mooeAllocated = mooeCategories.reduce((sum, category) => sum + Number(category.allocated || 0), 0);
  const mooeSpent = mooeCategories.reduce((sum, category) => sum + Number(category.spent || 0), 0);
  const totalAllocated = Number(summary.kpis?.total_budget ?? psAllocated + mooeAllocated);
  const totalSpent = Number(summary.kpis?.total_spent ?? psSpent + mooeSpent);
  const remaining = Number(summary.kpis?.remaining ?? totalAllocated - totalSpent);

  const greetingName = user?.name || project.project_leader || 'Program Leader';
  const roleLabel = user?.roleLabel || 'Program Leader';
  const statusColor = project.status_key === 'completed' ? 'var(--muted)' : 'var(--ok)';

  const programProjects = allProjectsForProgram.map((item, index) =>
    index === 0
      ? {
          ...item,
          name: project.title,
          leader: project.project_leader,
          budget: totalAllocated,
          spent: totalSpent,
          pct: Number(summary.kpis?.utilization_pct ?? pct(totalSpent, totalAllocated)),
        }
      : {
          ...item,
          spent: item.spent ?? Math.round(((Number(item.budget) || 0) * (Number(item.pct) || 0)) / 100),
        }
  );

  // Donut shares (of the circle) for PS spent / MOOE spent / remaining.
  const donutCircumference = 289;
  const donutPsLength = (donutCircumference * pct(psSpent, totalAllocated)) / 100;
  const donutMooeLength = (donutCircumference * pct(mooeSpent, totalAllocated)) / 100;
  const donutTotalPct = pct(totalSpent, totalAllocated);

  function showFeedback(tone, text) {
    setFeedback({ tone, text });
  }

  async function refreshLiveSummary() {
    try {
      const fresh = await fetchProgramDashboard(project.id);
      if (fresh && fresh.source !== 'mock') {
        setSummary(fresh);
        setUsingDemo(false);
      }
    } catch {
      // Keep the optimistic state if the background refresh fails.
    }
  }

  async function handleApprove(id) {
    setBusyApprovalId(id);
    setFeedback(null);

    try {
      await approveApproval(id);
      setSummary((previous) => {
        const nextApprovals = (previous.approvals || []).filter((approval) => approval.id !== id);
        return {
          ...previous,
          approvals: nextApprovals,
          kpis: { ...previous.kpis, pending_approvals: nextApprovals.length },
        };
      });
      showFeedback('ok', 'Approval recorded. Related records were updated.');
      refreshLiveSummary();
    } catch (error) {
      showFeedback(
        'over',
        error.response?.data?.message || 'Unable to approve this request. Please try again.'
      );
    } finally {
      setBusyApprovalId(null);
    }
  }

  async function handleReject(id, reason) {
    setBusyApprovalId(id);
    setFeedback(null);

    try {
      await rejectApproval(id, reason);
      setSummary((previous) => {
        const nextApprovals = (previous.approvals || []).filter((approval) => approval.id !== id);
        return {
          ...previous,
          approvals: nextApprovals,
          kpis: { ...previous.kpis, pending_approvals: nextApprovals.length },
        };
      });
      showFeedback('ok', 'Request rejected.');
      refreshLiveSummary();
    } catch (error) {
      showFeedback(
        'over',
        error.response?.data?.message || 'Unable to reject this request. Please try again.'
      );
    } finally {
      setBusyApprovalId(null);
    }
  }

  async function handleDownload({ type, compliance }) {
    setDownloadingReport(true);
    setFeedback(null);

    try {
      const { blob, filename } = await downloadReport(project.id, type, { compliance });
      saveBlob(blob, filename);
      showFeedback('ok', `Report downloaded: ${filename}`);
    } catch {
      showFeedback('over', 'The report could not be generated. Please try again.');
    } finally {
      setDownloadingReport(false);
    }
  }

  return (
    <div className="dashboard program-leader-dashboard" style={{ padding: '24px 26px 60px', maxWidth: '1180px', width: '100%' }}>
      {/* ---------- Header & Project Metadata ---------- */}
      <div className="panel" style={{ marginBottom: 24 }}>
        <div className="panel-head" style={{ padding: '16px 20px', display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 12, flex: 1, minWidth: 260 }}>
            <div className="seal">MSU</div>
            <div>
              <div style={{ fontSize: 11, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '.05em' }}>
                MOST R&amp;D Form B · Line-Item Budget
              </div>
              <h2 style={{ fontSize: 20, fontWeight: 700, margin: '2px 0 0', color: 'var(--ink)' }}>
                {project.title}
              </h2>
              <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap', marginTop: 6 }}>
                <span className="chip brand">{roleLabel}</span>
                <span style={{ fontSize: 12, color: 'var(--muted)' }}>{greetingName}</span>
                <span className={`chip ${usingDemo ? 'warn' : 'ok'}`}>
                  {loading ? 'Syncing…' : usingDemo ? 'Demo data' : 'Live data'}
                </span>
              </div>
            </div>
          </div>
          <div className="actions" style={{ marginLeft: 'auto' }}>
            <button className="btn primary" onClick={() => setFormBOpen(true)}>
              <PrinterIcon /> Export / Print Official LIB (Form B)
            </button>
          </div>
        </div>
        <div style={{ padding: '0 20px 16px', display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 10, fontSize: 13, color: 'var(--ink)' }}>
          <div><b>Project Code:</b> {project.code}</div>
          <div><b>Implementing Agency:</b> {project.implementing_agency}</div>
          <div><b>Monitoring Agency:</b> {project.funding_agency}</div>
          <div><b>Project Leader:</b> {project.project_leader}</div>
          <div><b>Project Period:</b> {project.period}</div>
          <div><b>Status:</b>{' '}
            <span style={{ textTransform: 'uppercase', fontSize: 11.5, fontWeight: 600, color: statusColor }}>
              {project.status}
            </span>
          </div>
        </div>
      </div>

      {feedback && <div className={`banner ${feedback.tone}`}>{feedback.text}</div>}

      {/* ---------- Top KPI Summary Cards ---------- */}
      <div className="grid c4" style={{ marginBottom: 24, gap: 14 }}>
        <StatCard
          label="Total MOST Funding"
          value={formatMoney(project.funding_budget)}
          sub={`${project.funding_agency} grant`}
        />
        <StatCard
          label="MSU Counterpart Funding"
          value={formatMoney(project.counterpart_budget)}
          sub={`${project.implementing_agency} share`}
        />
        <StatCard
          label="Total Disbursed"
          value={formatMoney(totalSpent)}
          sub={`${summary.kpis?.utilization_pct ?? pct(totalSpent, totalAllocated)}% of total budget`}
        />
        <StatCard
          label="Remaining Balance"
          value={formatMoney(remaining)}
          sub={`${pct(remaining, totalAllocated)}% unspent`}
        />
      </div>

      {/* ---------- Feature 1: Tranche / Fund Release Tracker ---------- */}
      <TrancheTimeline tranches={summary.tranches} />

      {/* ---------- Features 2 & 4: Burn Rate + Receipt Compliance ---------- */}
      <div className="grid c2" style={{ marginBottom: 24, gap: 14, alignItems: 'stretch' }}>
        <BurnRateCard burnRate={summary.burn_rate} />
        <ComplianceTracker compliance={summary.compliance} />
      </div>

      {/* ---------- Features 3 & 5: Quick Approvals + Export Shortcuts ---------- */}
      <div className="grid c2" style={{ marginBottom: 24, gap: 14, alignItems: 'start' }}>
        <QuickApprovals
          approvals={approvals}
          busyId={busyApprovalId}
          onApprove={handleApprove}
          onReject={handleReject}
        />
        <ExportShortcuts
          downloading={downloadingReport}
          onDownload={handleDownload}
          onPrintFormB={() => setFormBOpen(true)}
        />
      </div>

      {/* ---------- Interactive Charts ---------- */}
      <div className="grid c2-1" style={{ marginBottom: 24, gap: 14, alignItems: 'start' }}>
        <Panel title="Monthly Expenditure Trend" hint="Personnel Services (PS) vs Maintenance & Other Operating Expenses (MOOE) spending over time">
          <div style={{ padding: '18px 20px' }}>
            <LineChart data={monthlyExpenses} ariaLabel="Monthly expenditure trend: Personnel Services vs MOOE" />
          </div>
        </Panel>

        <Panel title="Budget Distribution" hint="PS spent, MOOE spent, and remaining balance">
          <div style={{ padding: '18px 20px', display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 12 }}>
            <DonutChart psLength={donutPsLength} mooeLength={donutMooeLength} totalPct={donutTotalPct} />
            <div style={{ width: '100%', display: 'flex', flexDirection: 'column', gap: 6, fontSize: 12.5 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                  <i style={{ width: 10, height: 10, borderRadius: 2, background: 'var(--maroon-soft)', display: 'inline-block' }} />
                  PS Spent
                </span>
                <b>{formatMoney(psSpent)}</b>
              </div>
              <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                  <i style={{ width: 10, height: 10, borderRadius: 2, background: 'var(--gold)', display: 'inline-block' }} />
                  MOOE Spent
                </span>
                <b>{formatMoney(mooeSpent)}</b>
              </div>
              <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                  <i style={{ width: 10, height: 10, borderRadius: 2, background: 'var(--line-strong)', display: 'inline-block' }} />
                  Remaining Balance
                </span>
                <b>{formatMoney(remaining)}</b>
              </div>
            </div>
          </div>
        </Panel>
      </div>

      {/* ---------- Project Budget Utilization List ---------- */}
      <Panel title="Project Budget Utilization" hint="Spent vs approved budget per project" style={{ marginBottom: 24 }}>
        <div style={{ padding: '8px 20px 16px' }}>
          <div style={{ display: 'flex', flexDirection: 'column' }}>
            {programProjects.map((item) => (
              <div key={item.name} style={{ display: 'flex', alignItems: 'center', gap: 14, padding: '11px 0', borderBottom: '1px solid var(--line)' }}>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, fontSize: 13, marginBottom: 5 }}>
                    <b style={{ color: 'var(--ink)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{item.name}</b>
                    <span style={{ fontWeight: 700, color: 'var(--ink)' }}>{item.pct}%</span>
                  </div>
                  <div className="util">
                    <div className="track">
                      <div className="fill" style={{ width: `${Math.max(0, Math.min(100, Number(item.pct) || 0))}%` }} />
                    </div>
                  </div>
                </div>
                <div style={{ textAlign: 'right', fontSize: 12, minWidth: 170 }}>
                  <div>Spent / Approved</div>
                  <b className="num">{formatMoney(item.spent ?? 0)} / {formatMoney(item.budget)}</b>
                </div>
              </div>
            ))}
          </div>
        </div>
      </Panel>

      {/* ---------- Dual-Funding Structure ---------- */}
      <div className="panel" style={{ marginBottom: 24 }}>
        <div className="panel-head" style={{ padding: '13px 16px' }}>
          <h3 style={{ fontSize: 14 }}>Budget by Funding Source</h3>
        </div>
        <div style={{ padding: '16px 20px 20px', display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 16, alignItems: 'start' }}>
          <div style={{ border: '1px solid var(--line-strong)', borderRadius: 8, padding: 16, background: 'var(--paper)' }}>
            <div style={{ fontSize: 11.5, color: 'var(--muted)', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '.05em', marginBottom: 8 }}>
              Implementing Agency Counterpart
            </div>
            <div style={{ fontSize: 26, fontWeight: 800, color: 'var(--ink)', marginBottom: 4 }}>
              {formatMoney(project.counterpart_budget)}
            </div>
            <div style={{ fontSize: 12, color: 'var(--muted)' }}>{project.implementing_agency} · counterpart share</div>
          </div>
          <div style={{ border: '1px solid var(--line-strong)', borderRadius: 8, padding: 16, background: 'var(--paper)' }}>
            <div style={{ fontSize: 11.5, color: 'var(--muted)', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '.05em', marginBottom: 8 }}>
              Funding Agency Allocation
            </div>
            <div style={{ fontSize: 26, fontWeight: 800, color: 'var(--ink)', marginBottom: 4 }}>
              {formatMoney(project.funding_budget)}
            </div>
            <div style={{ fontSize: 12, color: 'var(--muted)' }}>{project.funding_agency} · grant funding</div>
          </div>
          <div style={{ gridColumn: '1 / -1', border: '2px solid var(--maroon)', borderRadius: 8, padding: 14, background: 'var(--paper)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <div>
              <div style={{ fontSize: 11.5, color: 'var(--muted)', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '.05em', marginBottom: 4 }}>
                Overall Grand Total
              </div>
              <div style={{ fontSize: 22, fontWeight: 800, color: 'var(--ink)' }}>
                {formatMoney(project.total_budget)}
              </div>
            </div>
            <div style={{ fontSize: 11.5, color: 'var(--muted)', textAlign: 'right' }}>
              PS + MOOE combined<br />
              {pct(totalSpent, totalAllocated)}% utilized
            </div>
          </div>
        </div>
      </div>

      {/* ---------- Line-Item Tables: PS & MOOE ---------- */}
      <div className="grid c2-1" style={{ gap: 14 }}>
        <Panel title="I. Personnel Services (PS)" hint="Direct & Indirect Honoraria">
          <div style={{ padding: '0 20px 20px', overflowX: 'auto' }}>
            <table style={{ width: '100%', fontSize: 12.5, borderCollapse: 'collapse' }}>
              <thead>
                <tr style={{ textAlign: 'left', borderBottom: '1px solid var(--line-strong)' }}>
                  <th style={{ padding: '9px 10px', color: 'var(--muted)', fontSize: 11, fontWeight: 600, textAlign: 'left' }}>Line Item</th>
                  <th style={{ padding: '9px 10px', color: 'var(--muted)', fontSize: 11, fontWeight: 600, textAlign: 'right' }}>Allocated</th>
                  <th style={{ padding: '9px 10px', color: 'var(--muted)', fontSize: 11, fontWeight: 600, textAlign: 'right' }}>Disbursed</th>
                  <th style={{ padding: '9px 10px', color: 'var(--muted)', fontSize: 11, fontWeight: 600, textAlign: 'right' }}>Util.</th>
                </tr>
              </thead>
              <tbody>
                {psCategories.map((category) => (
                  <tr key={category.id ?? category.name} style={{ borderBottom: '1px solid var(--line)' }}>
                    <td style={{ padding: '8px 10px', color: 'var(--ink)' }}>{category.name}</td>
                    <td style={{ padding: '8px 10px', color: 'var(--ink)', textAlign: 'right' }}>{formatMoney(category.allocated)}</td>
                    <td style={{ padding: '8px 10px', color: 'var(--ink)', textAlign: 'right' }}>{formatMoney(category.spent)}</td>
                    <td style={{ padding: '8px 10px', color: 'var(--ok)', fontWeight: 600, textAlign: 'right' }}>{category.utilization_pct ?? pct(category.spent, category.allocated)}%</td>
                  </tr>
                ))}
                <tr style={{ borderTop: '2px solid var(--ink)', fontWeight: 700 }}>
                  <td style={{ padding: '10px', color: 'var(--ink)' }}>PS Subtotal</td>
                  <td style={{ padding: '10px', color: 'var(--ink)', textAlign: 'right' }}>{formatMoney(psAllocated)}</td>
                  <td style={{ padding: '10px', color: 'var(--ink)', textAlign: 'right' }}>{formatMoney(psSpent)}</td>
                  <td style={{ padding: '10px', color: 'var(--ok)', textAlign: 'right' }}>{pct(psSpent, psAllocated)}%</td>
                </tr>
              </tbody>
            </table>
          </div>
        </Panel>

        <Panel title="II. Maintenance & Other Operating Expenses (MOOE)" hint="Traveling, Supplies/IoT/Laptops, Communication, Professional Services, Cloud/Server Rent, Utilities">
          <div style={{ padding: '0 20px 20px', overflowX: 'auto' }}>
            <table style={{ width: '100%', fontSize: 12.5, borderCollapse: 'collapse' }}>
              <thead>
                <tr style={{ textAlign: 'left', borderBottom: '1px solid var(--line-strong)' }}>
                  <th style={{ padding: '9px 10px', color: 'var(--muted)', fontSize: 11, fontWeight: 600, textAlign: 'left' }}>Line Item</th>
                  <th style={{ padding: '9px 10px', color: 'var(--muted)', fontSize: 11, fontWeight: 600, textAlign: 'right' }}>Allocated</th>
                  <th style={{ padding: '9px 10px', color: 'var(--muted)', fontSize: 11, fontWeight: 600, textAlign: 'right' }}>Disbursed</th>
                  <th style={{ padding: '9px 10px', color: 'var(--muted)', fontSize: 11, fontWeight: 600, textAlign: 'right' }}>Util.</th>
                </tr>
              </thead>
              <tbody>
                {mooeCategories.map((category) => (
                  <tr key={category.id ?? category.name} style={{ borderBottom: '1px solid var(--line)' }}>
                    <td style={{ padding: '8px 10px', color: 'var(--ink)' }}>{category.name}</td>
                    <td style={{ padding: '8px 10px', color: 'var(--ink)', textAlign: 'right' }}>{formatMoney(category.allocated)}</td>
                    <td style={{ padding: '8px 10px', color: 'var(--ink)', textAlign: 'right' }}>{formatMoney(category.spent)}</td>
                    <td style={{ padding: '8px 10px', color: 'var(--warn)', fontWeight: 600, textAlign: 'right' }}>{category.utilization_pct ?? pct(category.spent, category.allocated)}%</td>
                  </tr>
                ))}
                <tr style={{ borderTop: '2px solid var(--ink)', fontWeight: 700 }}>
                  <td style={{ padding: '10px', color: 'var(--ink)' }}>MOOE Subtotal</td>
                  <td style={{ padding: '10px', color: 'var(--ink)', textAlign: 'right' }}>{formatMoney(mooeAllocated)}</td>
                  <td style={{ padding: '10px', color: 'var(--ink)', textAlign: 'right' }}>{formatMoney(mooeSpent)}</td>
                  <td style={{ padding: '10px', color: 'var(--warn)', textAlign: 'right' }}>{pct(mooeSpent, mooeAllocated)}%</td>
                </tr>
              </tbody>
            </table>
          </div>
        </Panel>
      </div>

      {/* ---------- Official Printable Form B ---------- */}
      <PrintableFormB
        open={formBOpen}
        onClose={() => setFormBOpen(false)}
        project={project}
        categoriesPS={psCategories}
        categoriesMOOE={mooeCategories}
      />
    </div>
  );
}
