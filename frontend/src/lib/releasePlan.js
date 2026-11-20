/**
 * Releases as portions of the funder's grant (the MOA releases it in parts,
 * e.g. 70% and 30%). The funder total comes from the server
 * (setup.funder_total: total budget less the counterpart), which also caps
 * the planned releases. Amounts are in pesos, rounded to centavos.
 */

import { formatMoneyText, parseMoney } from './moneyInput.js';

const cents = (value) => Math.round((Number(value) || 0) * 100);

/** Share of the funder total in percent, two decimals; null without a funder total. */
export function releaseShare(amount, funderTotal) {
  const total = cents(funderTotal);
  if (!(total > 0)) return null;
  return Math.round((cents(amount) / total) * 10000) / 100;
}

/** "70%", "33.33%", "12.5%"; '' for null. */
export function formatShare(percent) {
  if (percent == null || !Number.isFinite(Number(percent))) return '';
  return `${Number(Number(percent).toFixed(2))}%`;
}

/** Planned total, its share, and what is not yet scheduled (negative = over). */
export function releaseSummary(tranches, funderTotal) {
  const plannedCents = (tranches ?? []).reduce((sum, row) => sum + cents(row.amount), 0);
  const totalCents = cents(funderTotal);
  return {
    planned: plannedCents / 100,
    funderTotal: totalCents / 100,
    percent: releaseShare(plannedCents / 100, funderTotal),
    unscheduled: (totalCents - plannedCents) / 100,
  };
}

/** Percent text -> amount text with commas ("847,000.00"); '' when it cannot be computed. */
export function amountFromPercent(percentText, funderTotal) {
  const text = String(percentText ?? '').trim();
  if (text === '' || !/^\d+(\.\d+)?$/.test(text) || !(cents(funderTotal) > 0)) return '';
  return formatMoneyText((Math.round(cents(funderTotal) * Number(text) / 100) / 100).toFixed(2));
}

/** Amount text ("847,000.00" too) -> percent text (two decimals, no trailing zeros); '' when it cannot be computed. */
export function percentFromAmount(amountText, funderTotal) {
  const amount = parseMoney(amountText);
  if (amount.value === null) return '';
  const share = releaseShare(amount.value, funderTotal);
  return share == null ? '' : String(Number(share.toFixed(2)));
}

/** Percent field check: '' (optional) or 0 < p <= 100 with at most two decimals. */
export function percentError(percentText) {
  const text = String(percentText ?? '').trim();
  if (text === '') return null;
  if (!/^\d+(\.\d{1,2})?$/.test(text)) return 'Enter a percent with at most two decimals.';
  if (!(Number(text) > 0) || Number(text) > 100) return 'Enter a percent above 0 and at most 100.';
  return null;
}

/**
 * Amounts for shares of the funder total in whole centavos; the last takes
 * the remainder so they add up exactly (same rule as the server's split).
 */
export function splitAmounts(funderTotal, percents = [70, 30]) {
  const total = cents(funderTotal);
  let given = 0;
  return percents.map((percent, index) => {
    const share = index === percents.length - 1 ? total - given : Math.round(total * percent / 100);
    given += share;
    return share / 100;
  });
}

/** The liquidation reminder on the fund release request form (fund release only). */
export function requestFormWarning(type, trancheSummary) {
  return type === 'fund_release' ? trancheSummary?.liquidation_warning || null : null;
}

/** The same reminder beside Approve in the review panel (pending fund releases only). */
export function reviewPanelWarning(approval) {
  if (approval?.status !== 'pending' || approval?.details?.kind !== 'fund_release') return null;
  return approval.details.liquidation_warning || null;
}
