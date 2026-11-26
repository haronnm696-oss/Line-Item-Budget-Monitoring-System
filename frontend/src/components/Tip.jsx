import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import { CLOSED, createTip, placeTip } from '../lib/tipController';

/** What "Spent" means on every screen (ProjectFinancialService). */
export const SPENT_RULE = 'Spent = verified expenses and verified payroll';

/**
 * A short explanation on a word ("Spent", "Available"). The word is a real
 * button, so it is reachable by keyboard and read with its explanation
 * (aria-describedby).
 *
 * Opened by hover, it closes when the pointer leaves the button and the
 * bubble. Opened by tap/click or keyboard focus, it closes on a second tap,
 * a tap outside, Esc or blur. Only one tip is open at a time, and scrolling
 * or resizing closes it. Rules: lib/tipController.js.
 *
 * `icon`: show "ⓘ" instead of the word (next to a sort button, which cannot
 * hold another button); `label` then names it for screen readers.
 */
export default function Tip({ children, text = SPENT_RULE, icon = false, label, id: fixedId }) {
  const [state, setState] = useState(CLOSED);
  const autoId = useId();
  // A fixed id lets another control point at the text (aria-describedby).
  const id = fixedId ?? autoId;
  const ref = useRef(null);
  const btnRef = useRef(null);
  const bubbleRef = useRef(null);
  // One controller per Tip for its whole life (setState is stable).
  const [tip] = useState(() => createTip(setState));
  const open = state.open;

  useEffect(() => () => tip.dispose(), [tip]);

  useEffect(() => {
    if (!open) return undefined;
    const onDown = (event) => { if (!ref.current?.contains(event.target)) tip.send('outside'); };
    const onKey = (event) => { if (event.key === 'Escape') tip.send('escape'); };
    // Focusing a button off-screen scrolls it into view: ignore that first scroll.
    const openedAt = performance.now();
    const onDismiss = () => { if (performance.now() - openedAt > 120) tip.send('dismiss'); };
    document.addEventListener('pointerdown', onDown, true);
    document.addEventListener('keydown', onKey);
    window.addEventListener('scroll', onDismiss, true);
    window.addEventListener('resize', onDismiss);
    return () => {
      document.removeEventListener('pointerdown', onDown, true);
      document.removeEventListener('keydown', onKey);
      window.removeEventListener('scroll', onDismiss, true);
      window.removeEventListener('resize', onDismiss);
    };
  }, [open, tip]);

  // Place the bubble before paint so it never flashes in the wrong spot.
  useLayoutEffect(() => {
    const bubble = bubbleRef.current;
    const btn = btnRef.current;
    if (!open || !bubble || !btn) return;
    bubble.style.top = '0px';
    bubble.style.left = '0px';
    const size = bubble.getBoundingClientRect();
    const spot = placeTip(btn.getBoundingClientRect(), size, { width: window.innerWidth, height: window.innerHeight });
    // A transformed ancestor shifts position:fixed; correct by the offset seen at 0,0.
    bubble.style.top = `${spot.top - size.top}px`;
    bubble.style.left = `${spot.left - size.left}px`;
    bubble.dataset.side = spot.side;
  }, [open, text]);

  const hover = (event, name) => { if (event.pointerType !== 'touch') tip.send(name); };

  return (
    <span
      ref={ref}
      className="tip"
      onPointerEnter={(event) => hover(event, 'pointer-enter')}
      onPointerLeave={(event) => hover(event, 'pointer-leave')}
    >
      <button
        ref={btnRef}
        type="button"
        className={`tip-btn${icon ? ' is-icon' : ''}`}
        aria-describedby={id}
        aria-expanded={open}
        aria-label={icon ? (label ?? text) : undefined}
        onClick={(event) => { event.preventDefault(); event.stopPropagation(); tip.send('click'); }}
        onFocus={() => tip.send('focus')}
        onBlur={() => tip.send('blur')}
        onKeyDown={(event) => {
          // Esc closes the tip, not the dialog around it.
          if (event.key === 'Escape' && open) { event.stopPropagation(); tip.send('escape'); }
        }}
      >
        {icon ? <span aria-hidden="true">ⓘ</span> : children}
      </button>
      <span ref={bubbleRef} role="tooltip" id={id} className={`tip-bubble${open ? ' is-open' : ''}`}>{text}</span>
    </span>
  );
}
