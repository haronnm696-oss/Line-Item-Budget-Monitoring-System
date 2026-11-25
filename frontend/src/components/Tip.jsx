import { useEffect, useId, useRef, useState } from 'react';

/** What "Spent" means on every screen (ProjectFinancialService). */
export const SPENT_RULE = 'Spent = verified expenses and verified payroll';

/**
 * A short explanation on a word ("Spent", "Available"). It opens on hover,
 * on keyboard focus and on tap/click, and closes on Esc, on blur and on a
 * second tap. The word is a real button, so it is reachable by keyboard and
 * read with its explanation (aria-describedby).
 *
 * `icon`: show "ⓘ" instead of the word (next to a sort button, which cannot
 * hold another button); `label` then names it for screen readers.
 */
export default function Tip({ children, text = SPENT_RULE, icon = false, label, id: fixedId }) {
  const [open, setOpen] = useState(false);
  const autoId = useId();
  // A fixed id lets another control point at the text (aria-describedby).
  const id = fixedId ?? autoId;
  const ref = useRef(null);

  useEffect(() => {
    if (!open) return undefined;
    const onDown = (event) => { if (!ref.current?.contains(event.target)) setOpen(false); };
    document.addEventListener('pointerdown', onDown);
    return () => document.removeEventListener('pointerdown', onDown);
  }, [open]);

  return (
    <span
      ref={ref}
      className="tip"
      onMouseEnter={() => setOpen(true)}
      onMouseLeave={() => setOpen(false)}
    >
      <button
        type="button"
        className={`tip-btn${icon ? ' is-icon' : ''}`}
        aria-describedby={id}
        aria-expanded={open}
        aria-label={icon ? (label ?? text) : undefined}
        onClick={(event) => { event.preventDefault(); event.stopPropagation(); setOpen((value) => !value); }}
        onFocus={() => setOpen(true)}
        onBlur={() => setOpen(false)}
        onKeyDown={(event) => {
          if (event.key === 'Escape' && open) { event.stopPropagation(); setOpen(false); }
        }}
      >
        {icon ? <span aria-hidden="true">ⓘ</span> : children}
      </button>
      <span role="tooltip" id={id} className={`tip-bubble${open ? ' is-open' : ''}`}>{text}</span>
    </span>
  );
}
