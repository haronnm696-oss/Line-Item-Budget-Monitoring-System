import test from 'node:test';
import assert from 'node:assert/strict';
import { NO_LINE_ITEMS_TEXT, spendingAlert } from './leaderOverview.js';

const burn = { status_label: 'Under-spending', tone: 'warn', spent_pct: 10, elapsed_pct: 50, message: 'Spending is behind schedule.' };

test('no line items: the spending alert names the Program Leader and gives no verdict', () => {
  const alert = spendingAlert({ line_item_count: 0, burn_rate: { ...burn, status_label: 'At risk', tone: 'over' } });
  assert.equal(alert.setUp, false);
  assert.equal(alert.message, "The Program Leader hasn't set up the line-item budget yet.");
  assert.equal(alert.message, NO_LINE_ITEMS_TEXT);
  assert.equal(alert.label, undefined);
  assert.equal(alert.tone, undefined);
});

test('with line items: the burn-rate verdict and bars', () => {
  const alert = spendingAlert({ line_item_count: 3, burn_rate: burn });
  assert.equal(alert.setUp, true);
  assert.equal(alert.label, 'Under-spending');
  assert.equal(alert.tone, 'watch');
  assert.equal(alert.icon, '⚠');
  assert.equal(alert.spentPct, 10);
  assert.equal(alert.elapsedPct, 50);
  assert.equal(alert.message, 'Spending is behind schedule.');
});

test('an older payload without line_item_count keeps the verdict; an unknown tone is neutral', () => {
  const alert = spendingAlert({ burn_rate: { ...burn, tone: 'odd' } });
  assert.equal(alert.setUp, true);
  assert.equal(alert.tone, 'neutral');
});
