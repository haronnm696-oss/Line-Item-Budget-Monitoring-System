import assert from 'node:assert/strict';
import test from 'node:test';

import { ratesLabel, ratesLabelHtml } from './payrollRatesLabel.js';

const escape = (value) => String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

test('the label appears only when it is set', () => {
  assert.equal(ratesLabel('Demo deduction rates, not official'), 'Demo deduction rates, not official');
  assert.equal(ratesLabel('  Demo  '), 'Demo');
  assert.equal(ratesLabel(''), null);
  assert.equal(ratesLabel('   '), null);
  assert.equal(ratesLabel(null), null);
  assert.equal(ratesLabel(undefined), null);
});

test('the register PDF prints the label (escaped) only when set', () => {
  assert.equal(ratesLabelHtml(null, escape), '');
  assert.equal(ratesLabelHtml('', escape), '');
  const html = ratesLabelHtml('Demo <rates>', escape);
  assert.match(html, /Demo &lt;rates&gt;/);
  assert.match(html, /class="rates-label"/);
});
