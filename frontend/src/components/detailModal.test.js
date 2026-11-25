import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// No JSX renderer is set up for node:test, so these check the component sources.
const source = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');
const modal = source('./DetailModal.jsx');

const PANELS = [
  './program/ApprovalPanel.jsx',
  './program/RegistrationReviewPanel.jsx',
  './leader/RecordPanel.jsx',
  './finance/FinanceRecordPanel.jsx',
  './assistant/AssistantRecordPanel.jsx',
  '../pages/leader/Transactions.jsx',
];

test('every detail view uses the shared centered modal, not a right-side drawer', () => {
  for (const path of PANELS) {
    const text = source(path);
    assert.match(text, /<DetailModal/, path);
    assert.doesNotMatch(text, /ld-panel-overlay|className="ld-panel"/, path);
    assert.doesNotMatch(text, />Close<\/button>/, path);
  }
});

test('the modal closes on ✕, Esc and the background, asks first when a reason is unsaved', () => {
  assert.match(modal, /event\.key === 'Escape'/);
  assert.match(modal, /event\.target === event\.currentTarget\) requestClose\(\)/);
  assert.match(modal, /onClick=\{requestClose\}/);
  assert.match(modal, /dirty && !window\.confirm\(/);
  assert.match(modal, /aria-modal="true"/);
});

test('the modal traps focus, restores it to the opener and locks page scroll', () => {
  assert.match(modal, /event\.key !== 'Tab'/);
  assert.match(modal, /const opener = document\.activeElement/);
  assert.match(modal, /opener\.focus\(/);
  assert.match(modal, /body\.style\.overflow = 'hidden'/);
});

test('the footer renders only when there are actions, and has no Close button', () => {
  assert.match(modal, /\{actions && \(\s*<footer className="dm-foot">/);
  const footer = modal.slice(modal.indexOf('<footer'), modal.indexOf('</footer>'));
  assert.doesNotMatch(footer, /Close/);
});

test('the modal is centered (max 760px / 92vw / 88vh) and full screen under 760px', () => {
  const css = source('../styles/detail-modal.css');
  assert.match(css, /width:min\(760px,92vw\);max-height:88vh/);
  assert.match(css, /border-radius:18px/);
  assert.match(css, /background:rgba\(30,10,15,\.45\);backdrop-filter:blur/);
  assert.match(css, /@media \(max-width: 760px\)\{[\s\S]*?\.dm\{width:100%;max-height:none;height:100%/);
});
