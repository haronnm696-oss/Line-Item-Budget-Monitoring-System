import ActionMenu from './ActionMenu';

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
  return (
    <ActionMenu
      buttonContent={<>Download <span aria-hidden="true">▾</span>{label && <span className="pa-sr-only"> {label}</span>}</>}
      menuLabel={label ? `Download ${label}` : 'Download'}
      menuClassName="dl-menu"
      options={options}
      disabled={disabled}
      describedBy={describedBy}
      buttonClassName={buttonClassName}
      align={align}
    />
  );
}
