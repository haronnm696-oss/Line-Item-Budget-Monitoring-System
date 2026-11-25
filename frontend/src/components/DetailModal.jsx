import { useEffect, useRef } from 'react';
import { peso } from './money';
import '../styles/detail-modal.css';

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), iframe, [tabindex]:not([tabindex="-1"])';
const DISCARD_TEXT = 'You typed a reason that is not saved yet. Close and discard it?';

/** Highlighted note with a gold left border (justification, reason, return note). */
export function DetailNote({ label, children, tone = '' }) {
  return (
    <div className={`dm-note${tone ? ` is-${tone}` : ''}`} role="note">
      {label && <div className="dm-note-label">{label}</div>}
      <div className="dm-note-text">{children}</div>
    </div>
  );
}

/**
 * Shared centered modal for every record / approval detail view.
 *
 * - Header: icon tile, eyebrow, title (id = titleId), chips, amount, ✕ (the only close button).
 * - Body scrolls; footer stays pinned and holds `hint` plus `actions`.
 *   With no `actions` the footer is not rendered (view-only).
 * - Closes on ✕, Esc and a click on the dimmed background, unless
 *   `closeDisabled` (saving). With `dirty` (unsaved reason text) it asks first.
 * - Focus is trapped inside and returns to the opener on close; the page
 *   behind does not scroll while it is open.
 */
export default function DetailModal({
  titleId,
  title,
  eyebrow,
  icon = '▤',
  chips = null,
  amount = null,
  amountCaption = 'Amount',
  hint = '',
  actions = null,
  footerNotice = null,
  dirty = false,
  closeDisabled = false,
  onClose,
  children,
}) {
  const boxRef = useRef(null);
  const titleRef = useRef(null);

  function requestClose() {
    if (closeDisabled) return;
    if (dirty && !window.confirm(DISCARD_TEXT)) return;
    onClose();
  }

  // Remember the opener, focus inside, lock the page scroll; undo on close.
  useEffect(() => {
    const opener = document.activeElement;
    const box = boxRef.current;
    if (box && !box.contains(document.activeElement)) (titleRef.current ?? box).focus({ preventScroll: true });
    const { body } = document;
    const previous = body.style.overflow;
    body.style.overflow = 'hidden';
    return () => {
      body.style.overflow = previous;
      if (opener && typeof opener.focus === 'function' && document.contains(opener)) opener.focus({ preventScroll: true });
    };
    // Runs once per open: the modal mounts when a record is opened.
  }, []);

  function onKeyDown(event) {
    if (event.key === 'Escape') {
      event.stopPropagation();
      requestClose();
      return;
    }
    if (event.key !== 'Tab' || !boxRef.current) return;
    const nodes = [...boxRef.current.querySelectorAll(FOCUSABLE)].filter((node) => node.offsetParent !== null || node === document.activeElement);
    if (nodes.length === 0) {
      event.preventDefault();
      boxRef.current.focus();
      return;
    }
    const first = nodes[0];
    const last = nodes[nodes.length - 1];
    const active = document.activeElement;
    if (event.shiftKey && (active === first || active === boxRef.current)) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && active === last) {
      event.preventDefault();
      first.focus();
    }
  }

  return (
    <div
      className="dm-overlay"
      role="presentation"
      onMouseDown={(event) => { if (event.target === event.currentTarget) requestClose(); }}
      onKeyDown={onKeyDown}
    >
      <div ref={boxRef} className="dm" role="dialog" aria-modal="true" aria-labelledby={titleId} tabIndex={-1}>
        <header className="dm-head">
          <div className="dm-icon" aria-hidden="true">{icon}</div>
          <div className="dm-head-main">
            {eyebrow && <div className="dm-eyebrow">{eyebrow}</div>}
            <h2 className="dm-title" id={titleId} ref={titleRef} tabIndex={-1}>{title}</h2>
            {chips && <div className="dm-chips">{chips}</div>}
          </div>
          {amount != null && (
            <div className="dm-amount">
              <b>{peso(amount)}</b>
              <span>{amountCaption}</span>
            </div>
          )}
          <button type="button" className="program-icon-btn dm-close" onClick={requestClose} disabled={closeDisabled} aria-label="Close panel">×</button>
        </header>

        <div className="dm-body">{children}</div>

        {actions && (
          <footer className="dm-foot">
            {footerNotice && <div className="dm-foot-notice">{footerNotice}</div>}
            <div className="dm-foot-row">
              <p className="dm-hint">{hint}</p>
              <div className="dm-actions">{actions}</div>
            </div>
          </footer>
        )}
      </div>
    </div>
  );
}
