import test from 'node:test';
import assert from 'node:assert/strict';
import {
  amountFromPercent, formatShare, percentError, percentFromAmount, releaseShare, releaseSummary,
  requestFormWarning, reviewPanelWarning, splitAmounts,
} from './releasePlan.js';

test('each release shows its share of the funder total', () => {
  assert.equal(releaseShare(847000, 1210000), 70);
  assert.equal(formatShare(releaseShare(847000, 1210000)), '70%');
  assert.equal(formatShare(releaseShare(1, 3)), '33.33%');
  assert.equal(formatShare(releaseShare(125, 1000)), '12.5%');
  assert.equal(releaseShare(100, 0), null);
  assert.equal(formatShare(null), '');
});

test('the summary gives planned, its percent and what is not yet scheduled', () => {
  const summary = releaseSummary([{ amount: 847000 }, { amount: '121000.00' }], 1210000);
  assert.deepEqual(summary, { planned: 968000, funderTotal: 1210000, percent: 80, unscheduled: 242000 });
  assert.deepEqual(releaseSummary([], 500000), { planned: 0, funderTotal: 500000, percent: 0, unscheduled: 500000 });
  // Over the funder total (e.g. the budget was lowered later): negative.
  assert.equal(releaseSummary([{ amount: 600000 }], 500000).unscheduled, -100000);
});

test('percent and amount convert both ways, in centavos', () => {
  assert.equal(amountFromPercent('70', 1210000), '847000.00');
  assert.equal(amountFromPercent('33.33', 1000), '333.30');
  assert.equal(amountFromPercent('30', 1000000.01), '300000.00');
  assert.equal(percentFromAmount('847000', 1210000), '70');
  assert.equal(percentFromAmount('333.33', 1000), '33.33');
  assert.equal(percentFromAmount('363000.00', 1210000), '30');
  // Nothing to compute from.
  assert.equal(amountFromPercent('', 1000), '');
  assert.equal(amountFromPercent('abc', 1000), '');
  assert.equal(amountFromPercent('50', 0), '');
  assert.equal(percentFromAmount('100', 0), '');
  assert.equal(percentFromAmount('-5', 1000), '');
});

test('the percent field accepts 0 < p <= 100 with two decimals', () => {
  assert.equal(percentError(''), null);
  assert.equal(percentError('70'), null);
  assert.equal(percentError('33.33'), null);
  assert.equal(percentError('100'), null);
  assert.match(percentError('0'), /above 0/);
  assert.match(percentError('100.01'), /at most 100/);
  assert.match(percentError('1.234'), /two decimals/);
});

test('Split 70% / 30% totals exactly the funder amount', () => {
  assert.deepEqual(splitAmounts(1210000), [847000, 363000]);
  assert.deepEqual(splitAmounts(1000000.01), [700000.01, 300000]);
  for (const total of [1210000, 1000000.01, 999999.99, 0.03, 333333.33, 123456.78]) {
    const amounts = splitAmounts(total);
    assert.equal(amounts.length, 2);
    assert.equal(Math.round(amounts.reduce((a, b) => a + b, 0) * 100), Math.round(total * 100), `split of ${total}`);
    for (const amount of amounts) assert.equal(Math.round(amount * 100) / 100, amount);
  }
});

test('the request form shows the liquidation warning for a fund release only', () => {
  const summary = { liquidation_warning: 'Release 1 is not yet liquidated.' };
  assert.equal(requestFormWarning('fund_release', summary), 'Release 1 is not yet liquidated.');
  assert.equal(requestFormWarning('realignment', summary), null);
  assert.equal(requestFormWarning('fund_release', { liquidation_warning: null }), null);
  assert.equal(requestFormWarning('fund_release', undefined), null);
});

test('the review panel shows the same warning beside Approve while the fund release is pending', () => {
  const approval = { status: 'pending', details: { kind: 'fund_release', liquidation_warning: 'Release 1 is not yet liquidated.' } };
  assert.equal(reviewPanelWarning(approval), 'Release 1 is not yet liquidated.');
  assert.equal(reviewPanelWarning({ ...approval, status: 'approved' }), null);
  assert.equal(reviewPanelWarning({ status: 'pending', details: { kind: 'fund_release', liquidation_warning: null } }), null);
  assert.equal(reviewPanelWarning({ status: 'pending', details: { kind: 'liquidation' } }), null);
  assert.equal(reviewPanelWarning(null), null);
});
