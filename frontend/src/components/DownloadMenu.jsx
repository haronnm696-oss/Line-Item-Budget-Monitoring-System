import { useEffect, useId, useRef, useState } from 'react';

/**
 * One "Download" button that opens a small menu of formats (PDF, CSV…).
 * A real menu button (aria-haspopup="menu", aria-expanded): Escape or a
 * click outside closes it and focus returns to the button; arrow keys move
 * between items.
 *
 * options: [{ key, label, onSelect }]. `label` names what is downloaded
 * (read with the button's accessible name, e.g. "Download Expense ledger").
 */
export default function DownloadMenu({ label, options, disabled = false, describedBy, buttonClassName = 'program-btn', align = 'end' }) {
  const [open, setOpen] = useState(false);
  const buttonRef = useRef(null);
  const menuRef = useRef(null);
  const menuId = useId();
  const isOpen = open && !disabled;

  useEffect(() => {
    if (!isOpen) return undefined;
    menuRef.current?.querySelector('[role="menuitem"]')?.focus();

    function onPointer(event) {
      if (!menuRef.current?.contains(event.target) && !buttonRef.current?.contains(event.target)) {
        setOpen(false);
      }
    }
    document.addEventListener('mousedown', onPointer);
    return () => document.removeEventListener('mousedown', onPointer);
  }, [isOpen]);

  function close() {
    setOpen(false);
    buttonRef.current?.focus();
  }

  function onMenuKeyDown(event) {
    const items = [...(menuRef.current?.querySelectorAll('[role="menuitem"]') ?? [])];
    const index = items.indexOf(document.activeElement);
    if (event.key === 'Escape') {
      event.preventDefault();
      close();
    } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      const step = event.key === 'ArrowDown' ? 1 : -1;
      items[(index + step + items.length) % items.length]?.focus();
    } else if (event.key === 'Home' || event.key === 'End') {
      event.preventDefault();
      (event.key === 'Home' ? items[0] : items[items.length - 1])?.focus();
    } else if (event.key === 'Tab') {
      setOpen(false);
    }
  }

  return (
    <div className="pu-menu-wrap">
      <button
        ref={buttonRef}
        type="button"
        className={buttonClassName}
        aria-haspopup="menu"
        aria-expanded={isOpen}
        aria-controls={isOpen ? menuId : undefined}
        aria-describedby={describedBy}
        disabled={disabled}
        onClick={() => setOpen((value) => !value)}
      >
        Download <span aria-hidden="true">▾</span>
        {label && <span className="pa-sr-only"> {label}</span>}
      </button>
      {isOpen && (
        <div
          ref={menuRef}
          id={menuId}
          role="menu"
          aria-label={label ? `Download ${label}` : 'Download'}
          className={`pu-menu dl-menu${align === 'start' ? ' is-start' : ''}`}
          onKeyDown={onMenuKeyDown}
        >
          {options.map((option) => (
            <button
              key={option.key}
              type="button"
              role="menuitem"
              className="pu-menu-item"
              onClick={() => { setOpen(false); option.onSelect(); }}
            >
              {option.label}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
