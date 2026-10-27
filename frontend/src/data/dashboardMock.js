// Demo payload used when the dashboard API is unavailable (VITE_USE_MOCK or a
// 404/501 from the backend). Shapes mirror DashboardService::build() exactly.
import {
  CURRENT_PROJECT,
  libCategoriesPS,
  libCategoriesMOOE,
  pendingApprovals,
  expenses,
} from './mockData';

function pct(part, whole) {
  return whole > 0 ? Math.round((part / whole) * 1000) / 10 : 0;
}

const withType = (rows, type, offset) =>
  rows.map((row, index) => {
    const remaining = row.allocated - row.spent;
    return {
      id: offset + index,
      type,
      name: row.name,
      code: `${type}-${String(index + 1).padStart(2, '0')}`,
      allocated: row.allocated,
      spent: row.spent,
      remaining,
      utilization_pct: pct(row.spent, row.allocated),
    };
  });

export function buildMockDashboard() {
  const ps = withType(libCategoriesPS, 'PS', 1);
  const mooe = withType(libCategoriesMOOE, 'MOOE', 100);
  const totalAllocated = [...ps, ...mooe].reduce((sum, category) => sum + category.allocated, 0);
  const totalSpent = [...ps, ...mooe].reduce((sum, category) => sum + category.spent, 0);
  const remaining = totalAllocated - totalSpent;

  const trancheDefs = [
    {
      sequence: 1,
      name: 'Tranche 1 — Project Mobilization',
      percentage: 40,
      status: 'released',
      planned_release_date: '2026-01-15',
      released_at: '2026-01-20',
      notes: 'Initial release covering personnel services and startup procurement.',
    },
    {
      sequence: 2,
      name: 'Tranche 2 — Mid-year Milestone',
      percentage: 30,
      status: 'released',
      planned_release_date: '2026-05-15',
      released_at: '2026-06-02',
      notes: 'Released after the first progress report and financial liquidation.',
    },
    {
      sequence: 3,
      name: 'Tranche 3 — Field Validation',
      percentage: 20,
      status: 'due',
      planned_release_date: '2026-09-30',
      released_at: null,
      notes: 'Pending Program Leader approval of the field validation milestone.',
    },
    {
      sequence: 4,
      name: 'Tranche 4 — Final Report & Closeout',
      percentage: 10,
      status: 'upcoming',
      planned_release_date: '2026-12-15',
      released_at: null,
      notes: 'Final release upon acceptance of the terminal report.',
    },
  ];

  const trancheItems = trancheDefs.map((tranche) => ({
    id: tranche.sequence,
    ...tranche,
    amount: Math.round(totalAllocated * (tranche.percentage / 100) * 100) / 100,
    percentage: tranche.percentage,
    display_status: tranche.status,
    is_released: tranche.status === 'released',
  }));
  const releasedAmount = trancheItems
    .filter((tranche) => tranche.is_released)
    .reduce((sum, tranche) => sum + tranche.amount, 0);

  const start = new Date('2026-01-01T00:00:00');
  const end = new Date('2026-12-31T00:00:00');
  const today = new Date();
  const totalDays = Math.max(1, Math.round((end - start) / 86400000));
  const elapsedDays = Math.min(totalDays, Math.max(0, Math.round((today - start) / 86400000)));
  const elapsedPct = Math.round(Math.min(100, (elapsedDays / totalDays) * 100) * 10) / 10;
  const spentPct = pct(totalSpent, totalAllocated);
  const variance = Math.round((spentPct - elapsedPct) * 10) / 10;
  const monthsElapsed = Math.max(0.1, elapsedDays / 30.44);
  const monthlyBurn = elapsedDays > 0 ? Math.round((totalSpent / monthsElapsed) * 100) / 100 : 0;
  const projectedTotal = Math.round(monthlyBurn * Math.max(0.1, totalDays / 30.44) * 100) / 100;
  const underSpending = variance < -15;
  const highRisk = totalSpent > totalAllocated || projectedTotal > totalAllocated * 1.05 || variance > 15;

  const missingItems = expenses.slice(0, 3).map((expense) => ({
    id: expense.id,
    reference_no: expense.ref,
    description: expense.desc,
    amount: expense.amount,
    expense_date: expense.date,
    status: expense.status,
    category: expense.category,
  }));
  const withReceipt = Math.max(0, expenses.length - missingItems.length);
  const compliancePct = pct(withReceipt, expenses.length);

  const approvals = pendingApprovals.map((approval, index) => ({
    id: 900 + index,
    project_id: CURRENT_PROJECT.id,
    project_tranche_id: index === 0 ? null : 3,
    tranche_name: index === 0 ? null : 'Tranche 3 — Field Validation',
    type: index === 0 ? 'payroll' : 'budget_realignment',
    type_label: index === 0 ? 'Payroll' : 'Budget Realignment',
    title: approval.title,
    description: approval.detail,
    amount: index === 0 ? 70475 : 40000,
    status: 'pending',
    payload: {},
    requested_by: 'Norjannah Haron',
    created_at: new Date().toISOString(),
  }));

  return {
    source: 'mock',
    project: {
      ...CURRENT_PROJECT,
      status_key: 'ongoing',
      start_date: '2026-01-01',
      end_date: '2026-12-31',
      total_spent: totalSpent,
      remaining,
      utilization_pct: spentPct,
    },
    kpis: {
      total_budget: totalAllocated,
      total_spent: totalSpent,
      remaining,
      utilization_pct: spentPct,
      pending_approvals: approvals.length,
      tranches_released: 2,
      tranches_total: trancheItems.length,
    },
    categories: { ps, mooe },
    tranches: {
      items: trancheItems,
      summary: {
        total_count: trancheItems.length,
        released_count: 2,
        released_amount: releasedAmount,
        upcoming_amount: totalAllocated - releasedAmount,
        total_amount: totalAllocated,
        progress_pct: pct(releasedAmount, totalAllocated),
      },
    },
    burn_rate: {
      status: highRisk ? 'high_risk' : underSpending ? 'under_spending' : 'on_track',
      status_label: highRisk ? 'High Burn Rate Risk' : underSpending ? 'Under-spending' : 'On Track',
      tone: highRisk ? 'over' : underSpending ? 'warn' : 'ok',
      message: highRisk
        ? 'Spending is running ahead of the project timeline. Review the burn forecast.'
        : underSpending
          ? 'Budget consumption is behind the elapsed project timeline; review work plan acceleration.'
          : 'Budget consumption is broadly aligned with the elapsed project timeline.',
      budget: totalAllocated,
      spent: totalSpent,
      remaining,
      elapsed_pct: elapsedPct,
      spent_pct: spentPct,
      variance_pct: variance,
      elapsed_days: elapsedDays,
      total_days: totalDays,
      days_remaining: Math.max(0, Math.round((end - today) / 86400000)),
      months_elapsed: Math.round(monthsElapsed * 10) / 10,
      monthly_burn: monthlyBurn,
      projected_total: projectedTotal,
      forecast_variance: Math.round((projectedTotal - totalAllocated) * 100) / 100,
    },
    approvals,
    compliance: {
      total_expenses: expenses.length,
      with_receipt: withReceipt,
      missing_receipt: missingItems.length,
      verified: expenses.filter((expense) => expense.status === 'Verified').length,
      pending: expenses.filter((expense) => expense.status === 'Pending').length,
      rejected: expenses.filter((expense) => expense.status === 'Rejected').length,
      verified_with_receipt: expenses.filter(
        (expense) => expense.status === 'Verified' && !missingItems.some((item) => item.id === expense.id)
      ).length,
      compliance_pct: compliancePct,
      verified_pct: pct(
        expenses.filter(
          (expense) => expense.status === 'Verified' && !missingItems.some((item) => item.id === expense.id)
        ).length,
        expenses.length
      ),
      status: compliancePct >= 90 ? 'Audit Ready' : compliancePct >= 70 ? 'Needs Attention' : 'High Compliance Risk',
      tone: compliancePct >= 90 ? 'ok' : compliancePct >= 70 ? 'warn' : 'over',
      missing_items: missingItems,
    },
    funding_sources: {
      counterpart: CURRENT_PROJECT.counterpart_budget,
      funding: CURRENT_PROJECT.funding_budget,
      total: CURRENT_PROJECT.total_budget,
    },
  };
}
