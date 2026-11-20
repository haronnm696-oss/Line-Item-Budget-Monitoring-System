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
export function printHtmlDocument(html, { title } = {}) {
  const frame = document.createElement('iframe');
  // Browsers name a saved PDF after the page title: use the document's own
  // title while the print dialog is open, then restore it.
  const pageTitle = document.title;
  const restoreTitle = () => { document.title = pageTitle; };
  frame.setAttribute('aria-hidden', 'true');
  frame.tabIndex = -1;
  Object.assign(frame.style, { position: 'fixed', right: '0', bottom: '0', width: '0', height: '0', border: '0' });
  frame.onload = () => {
    const view = frame.contentWindow;
    if (!view) return;
    const cleanup = () => { restoreTitle(); window.setTimeout(() => frame.remove(), 0); };
    view.addEventListener('afterprint', cleanup, { once: true });
    if (title) document.title = title;
    view.focus();
    view.print();
    // Fallback for browsers that do not fire afterprint.
    window.setTimeout(() => { restoreTitle(); if (frame.isConnected) frame.remove(); }, 60000);
  };
  frame.srcdoc = html;
  document.body.appendChild(frame);
}
