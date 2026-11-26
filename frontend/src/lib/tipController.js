// Open/close rules and placement for components/Tip.jsx. Kept free of JSX
// and of the DOM so it can be tested with node:test.
//
// A tip opened by hover ('hover') closes when the pointer leaves the button
// and the bubble (after a short delay, so the pointer can cross the gap).
// A tip opened by keyboard focus ('focus') or tap/click ('pinned') stays
// until a second tap, a tap outside, Esc or blur. Only one tip is open at a
// time, and scroll/resize close it ('dismiss').

export const CLOSED = Object.freeze({ open: false, mode: null });
const HOVER = Object.freeze({ open: true, mode: 'hover' });
const FOCUS = Object.freeze({ open: true, mode: 'focus' });
const PINNED = Object.freeze({ open: true, mode: 'pinned' });

export const CLOSE_DELAY = 150;

/** The next state for one event (returns the same object when nothing changes). */
export function tipReducer(state, event) {
  switch (event) {
    case 'pointer-enter':
      return state.open ? state : HOVER;
    case 'hover-leave':
      return state.mode === 'hover' ? CLOSED : state;
    case 'click':
      // A tap on a hovered or focused tip keeps it open; a second tap closes it.
      return state.mode === 'pinned' ? CLOSED : PINNED;
    case 'focus':
      return state.open ? state : FOCUS;
    case 'blur':
      return state.open && state.mode !== 'hover' ? CLOSED : state;
    case 'escape':
    case 'outside':
    case 'dismiss':
      return state.open ? CLOSED : state;
    default:
      return state;
  }
}

// The one tip that is open now (opening another closes it).
let active = null;

/**
 * One tip's controller. `onChange(state)` runs whenever it opens or closes.
 * Events: pointer-enter, pointer-leave, click, focus, blur, escape, outside,
 * dismiss. `timers` defaults to the global setTimeout/clearTimeout.
 */
export function createTip(onChange, { closeDelay = CLOSE_DELAY, timers = globalThis } = {}) {
  let state = CLOSED;
  let timer = null;

  const cancel = () => {
    if (timer !== null) {
      timers.clearTimeout(timer);
      timer = null;
    }
  };

  const apply = (event) => {
    const next = tipReducer(state, event);
    if (next === state) return;
    const wasOpen = state.open;
    state = next;
    if (state.open && !wasOpen) {
      if (active && active !== api) active.send('dismiss');
      active = api;
    } else if (!state.open && active === api) {
      active = null;
    }
    onChange(state);
  };

  const api = {
    get state() { return state; },
    send(event) {
      if (event === 'pointer-leave') {
        cancel();
        if (state.mode === 'hover') {
          timer = timers.setTimeout(() => { timer = null; apply('hover-leave'); }, closeDelay);
        }
        return;
      }
      cancel();
      apply(event);
    },
    dispose() {
      cancel();
      if (active === api) active = null;
    },
  };
  return api;
}

/**
 * Where the bubble goes (viewport coordinates). It prefers above the word,
 * so it does not cover the value below it, flips below when there is no room
 * above, aligns to the word's left edge and flips to its right edge near the
 * right side, and always stays `margin` px inside the viewport.
 *
 * anchor: { top, bottom, left, right }; bubble: { width, height };
 * viewport: { width, height }.
 */
export function placeTip(anchor, bubble, viewport, { gap = 6, margin = 8 } = {}) {
  const above = anchor.top - gap - bubble.height;
  const below = anchor.bottom + gap;
  let top;
  let side;
  if (above >= margin) {
    top = above; side = 'above';
  } else if (below + bubble.height <= viewport.height - margin) {
    top = below; side = 'below';
  } else {
    // No full room either way: use the side with more space.
    const roomAbove = anchor.top;
    const roomBelow = viewport.height - anchor.bottom;
    side = roomAbove > roomBelow ? 'above' : 'below';
    top = side === 'above' ? above : below;
  }
  top = clamp(top, margin, viewport.height - margin - bubble.height);

  let left = anchor.left;
  if (left + bubble.width > viewport.width - margin) left = anchor.right - bubble.width;
  left = clamp(left, margin, viewport.width - margin - bubble.width);

  return { top: Math.round(top), left: Math.round(left), side };
}

function clamp(value, min, max) {
  // When the bubble is larger than the room, keep its start edge visible.
  return Math.max(min, Math.min(value, Math.max(min, max)));
}
