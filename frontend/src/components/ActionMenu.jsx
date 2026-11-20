import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';

/**
 * A menu button (aria-haspopup="menu", aria-expanded). Escape or a click
 * outside closes it and focus returns to the button; arrow keys, Home and
 * End move between items.
 *
 * options: [{ key, label, onSelect, danger?, disabled?, note? }]. A disabled
 * option stays in the menu (aria-disabled) with its `note` saying why.
 *
 * `fixed` places the menu with position: fixed under the button, so it is
 * not clipped by a scrolling table (.program-scroll); it flips above the
 * button near the bottom of the window and closes when the page scrolls.
 */
export default function ActionMenu({
  buttonContent,
  menuLabel,
  options,
  disabled = false,
  describedBy,
  buttonClassName = 'program-btn',
  menuClassName = '',
  align = 'end',
  fixed = false,
}) {
  const [open, setOpen] = useState(false);
  const [place, setPlace] = useState(null);
  const buttonRef = useRef(null);
  const menuRef = useRef(null);
  const menuId = useId();
  const isOpen = open && !disabled;

  useLayoutEffect(() => {
    if (!isOpen || !fixed) return;
    const button = buttonRef.current?.getBoundingClientRect();
    const menu = menuRef.current?.getBoundingClientRect();
    if (!button || !menu) return;
    const below = button.bottom + 6;
    const top = below + menu.height > window.innerHeight - 8 && button.top - 6 - menu.height > 8
      ? button.top - 6 - menu.height
      : below;
    const left = Math.max(8, Math.min(button.right - menu.width, window.innerWidth - menu.width - 8));
    setPlace({ top, left });
  }, [isOpen, fixed]);

  useEffect(() => {
    if (!isOpen) return undefined;
    menuRef.current?.querySelector('[role="menuitem"]:not([aria-disabled="true"])')?.focus();

    function onPointer(event) {
      if (!menuRef.current?.contains(event.target) && !buttonRef.current?.contains(event.target)) {
        setOpen(false);
      }
    }
    function onScroll(event) {
      if (!menuRef.current?.contains(event.target)) setOpen(false);
    }
    document.addEventListener('mousedown', onPointer);
    if (fixed) {
      window.addEventListener('scroll', onScroll, true);
      window.addEventListener('resize', onScroll);
    }
    return () => {
      document.removeEventListener('mousedown', onPointer);
      window.removeEventListener('scroll', onScroll, true);
      window.removeEventListener('resize', onScroll);
    };
  }, [isOpen, fixed]);

  function toggle() {
    setPlace(null);
    setOpen((value) => !value);
  }

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

  function choose(option) {
    if (option.disabled) return;
    setOpen(false);
    option.onSelect();
  }

  const fixedStyle = fixed
    ? { position: 'fixed', right: 'auto', top: place?.top ?? 0, left: place?.left ?? 0, visibility: place ? 'visible' : 'hidden' }
    : undefined;

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
        onClick={toggle}
      >
        {buttonContent}
      </button>
      {isOpen && (
        <div
          ref={menuRef}
          id={menuId}
          role="menu"
          aria-label={menuLabel}
          className={`pu-menu${menuClassName ? ` ${menuClassName}` : ''}${align === 'start' ? ' is-start' : ''}`}
          style={fixedStyle}
          onKeyDown={onMenuKeyDown}
        >
          {options.map((option) => (
            <button
              key={option.key}
              type="button"
              role="menuitem"
              className={`pu-menu-item${option.danger ? ' danger' : ''}`}
              aria-disabled={option.disabled || undefined}
              title={option.disabled ? option.note : undefined}
              onClick={() => choose(option)}
            >
              <span>
                {option.label}
                {option.disabled && option.note && <span className="pu-menu-note">{option.note}</span>}
              </span>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

/** The "⋯" actions menu of one table or list row. `name` names the row. */
export function RowMenu({ name, options }) {
  if (!options?.length) return null;
  return (
    <ActionMenu
      fixed
      buttonClassName="program-btn sm pd-row-menu"
      buttonContent={<><span aria-hidden="true">⋯</span><span className="pa-sr-only">Actions for {name}</span></>}
      menuLabel={`Actions for ${name}`}
      menuClassName="pd-row-menu-list"
      options={options}
    />
  );
}
