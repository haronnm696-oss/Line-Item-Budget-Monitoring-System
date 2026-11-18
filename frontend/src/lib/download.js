// Browser downloads and printing, shared by Reports and the Payroll pages.

/** Saves a Blob under `filename` (CSV from the backend). */
export function saveBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = filename;
  anchor.style.visibility = 'hidden';
  document.body.appendChild(anchor);
  anchor.click();
  document.body.removeChild(anchor);
  URL.revokeObjectURL(url);
}

// Opens the browser print dialog (where "Save as PDF" is available) for a
// standalone HTML document, using a hidden iframe so no pop-up is needed.
export function printHtmlDocument(html) {
  const frame = document.createElement('iframe');
  frame.setAttribute('aria-hidden', 'true');
  frame.tabIndex = -1;
  Object.assign(frame.style, { position: 'fixed', right: '0', bottom: '0', width: '0', height: '0', border: '0' });
  frame.onload = () => {
    const view = frame.contentWindow;
    if (!view) return;
    const cleanup = () => window.setTimeout(() => frame.remove(), 0);
    view.addEventListener('afterprint', cleanup, { once: true });
    view.focus();
    view.print();
    // Fallback for browsers that do not fire afterprint.
    window.setTimeout(() => { if (frame.isConnected) frame.remove(); }, 60000);
  };
  frame.srcdoc = html;
  document.body.appendChild(frame);
}
