import test from 'node:test';
import assert from 'node:assert/strict';
import { reportDownloadOptions, reportsEyebrow } from './reportDownloads.js';

test('every report downloads as PDF or CSV; Form B names its layout', () => {
  assert.deepEqual(reportDownloadOptions('ledger').map((o) => o.label), ['PDF', 'CSV']);
  assert.deepEqual(reportDownloadOptions('form-b').map((o) => o.label), ['Form B (PDF)', 'CSV']);
  assert.deepEqual(reportDownloadOptions('form-b').map((o) => o.key), ['pdf', 'csv']);
});

test('the Reports eyebrow follows the sidebar group of each role', () => {
  assert.equal(reportsEyebrow('PL'), 'Output · Project Leader');
  assert.equal(reportsEyebrow('FIN'), 'Records · Finance/Admin');
  assert.equal(reportsEyebrow('RES'), 'View · Researcher');
});
