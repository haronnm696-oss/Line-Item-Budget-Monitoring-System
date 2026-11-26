import { test, mock, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { CLOSE_DELAY, createTip, placeTip } from './tipController.js';

const made = [];
function tip() {
  const changes = [];
  const t = createTip((state) => changes.push(state));
  made.push(t);
  return { t, changes };
}

afterEach(() => {
  while (made.length) made.pop().dispose();
  mock.timers.reset();
});

test('hover opens; leaving the button and the bubble closes after the short delay', () => {
  mock.timers.enable({ apis: ['setTimeout'] });
  const { t } = tip();
  t.send('pointer-enter');
  assert.deepEqual(t.state, { open: true, mode: 'hover' });
  t.send('pointer-leave');
  mock.timers.tick(CLOSE_DELAY - 1);
  assert.equal(t.state.open, true, 'still open during the delay');
  mock.timers.tick(1);
  assert.equal(t.state.open, false);
});

test('crossing from the button to the bubble (leave then enter) keeps it open', () => {
  mock.timers.enable({ apis: ['setTimeout'] });
  const { t } = tip();
  t.send('pointer-enter');
  t.send('pointer-leave');
  mock.timers.tick(50);
  t.send('pointer-enter');
  mock.timers.tick(CLOSE_DELAY * 2);
  assert.equal(t.state.open, true);
});

test('a tip opened by click stays open when the pointer leaves', () => {
  mock.timers.enable({ apis: ['setTimeout'] });
  const { t } = tip();
  t.send('pointer-enter');
  t.send('click');
  t.send('pointer-leave');
  mock.timers.tick(CLOSE_DELAY * 2);
  assert.deepEqual(t.state, { open: true, mode: 'pinned' });
});

test('a second tap closes a tapped tip', () => {
  const { t } = tip();
  t.send('click');
  assert.equal(t.state.open, true);
  t.send('click');
  assert.equal(t.state.open, false);
});

test('a click outside closes it (opened by click, focus or hover)', () => {
  for (const opener of ['click', 'focus', 'pointer-enter']) {
    const { t } = tip();
    t.send(opener);
    assert.equal(t.state.open, true, opener);
    t.send('outside');
    assert.equal(t.state.open, false, opener);
  }
});

test('Esc closes it (opened by click, focus or hover)', () => {
  for (const opener of ['click', 'focus', 'pointer-enter']) {
    const { t } = tip();
    t.send(opener);
    t.send('escape');
    assert.equal(t.state.open, false, opener);
  }
});

test('blur closes a focused or tapped tip; scroll/resize close any tip', () => {
  const a = tip().t;
  a.send('focus');
  a.send('blur');
  assert.equal(a.state.open, false);

  const b = tip().t;
  b.send('pointer-enter');
  b.send('dismiss');
  assert.equal(b.state.open, false);
});

test('only one tip is open at a time: opening another closes the previous', () => {
  mock.timers.enable({ apis: ['setTimeout'] });
  const first = tip();
  const second = tip();
  first.t.send('click');
  assert.equal(first.t.state.open, true);
  second.t.send('pointer-enter');
  assert.equal(second.t.state.open, true);
  assert.equal(first.t.state.open, false, 'the first one closed');
  assert.equal(first.changes.at(-1).open, false, 'and told its component');

  second.t.send('pointer-leave'); // its close timer is now pending
  first.t.send('focus');
  assert.equal(first.t.state.open, true);
  assert.equal(second.t.state.open, false);
  // The closed tip's cancelled timer must not change anything.
  mock.timers.tick(CLOSE_DELAY * 2);
  assert.equal(first.t.state.open, true);
});

const viewport = { width: 1000, height: 800 };
const bubble = { width: 200, height: 60 };

test('placement prefers above the word, so the value below stays visible', () => {
  const spot = placeTip({ top: 300, bottom: 320, left: 100, right: 160 }, bubble, viewport);
  assert.equal(spot.side, 'above');
  assert.equal(spot.top, 300 - 6 - 60);
  assert.equal(spot.left, 100);
});

test('placement flips below near the top, and to the left near the right edge', () => {
  const spot = placeTip({ top: 20, bottom: 40, left: 900, right: 960 }, bubble, viewport);
  assert.equal(spot.side, 'below');
  assert.equal(spot.top, 46);
  assert.equal(spot.left, 960 - 200);
});

test('placement always stays inside the viewport', () => {
  const small = { width: 320, height: 500 };
  const spot = placeTip({ top: 10, bottom: 490, left: 300, right: 318 }, { width: 280, height: 90 }, small);
  assert.ok(spot.left >= 8 && spot.left + 280 <= 320 - 8);
  assert.ok(spot.top >= 8 && spot.top + 90 <= 500 - 8);
});
