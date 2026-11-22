import { useState } from 'react';
import { downloadPurchaseRequestCsv, fetchPurchaseRequestForm } from '../api/reports';
import { useAuth } from '../context/AuthContext';
import { printHtmlDocument, saveBlob } from '../lib/download';
import { normalizeRole } from '../lib/roles';
import { canDownloadPurchaseRequest, PURCHASE_REQUEST_DOWNLOADS, purchaseRequestHtml, purchaseRequestTitle } from '../lib/purchaseRequestPrint';
import DownloadMenu from './DownloadMenu';

/**
 * "Download ▾" on a purchase request's detail panel: the MSU Purchase Request
 * form as PDF (the print dialog's "Save as PDF", same print setup as Form B)
 * or CSV. Research Assistant, Project Leader, Finance/Admin and the Program
 * Leader; hidden for Researchers (the API answers 403 for them too).
 */
export default function PurchaseRequestDownload({ requestId, reference, buttonClassName = 'program-btn', align = 'end' }) {
  const { user } = useAuth();
  const [message, setMessage] = useState(null); // { tone, text }
  if (!requestId || !canDownloadPurchaseRequest(normalizeRole(user))) return null;

  async function pdf() {
    setMessage({ tone: 'info', text: 'Preparing the purchase request…' });
    try {
      const doc = await fetchPurchaseRequestForm(requestId);
      printHtmlDocument(purchaseRequestHtml(doc), { title: purchaseRequestTitle(doc) });
      setMessage(null);
    } catch {
      setMessage({ tone: 'error', text: 'Could not load the purchase request. Please try again.' });
    }
  }

  async function csv() {
    setMessage({ tone: 'info', text: 'Exporting the purchase request to CSV…' });
    try {
      const { blob, filename } = await downloadPurchaseRequestCsv(requestId);
      saveBlob(blob, filename);
      setMessage(null);
    } catch {
      setMessage({ tone: 'error', text: 'Could not export the purchase request. Please try again.' });
    }
  }

  const actions = { pdf, csv };

  return (
    <>
      <DownloadMenu
        label={reference ? `purchase request ${reference}` : 'purchase request'}
        options={PURCHASE_REQUEST_DOWNLOADS.map((option) => ({ ...option, onSelect: actions[option.key] }))}
        buttonClassName={buttonClassName}
        align={align}
      />
      {message && (
        <span className={message.tone === 'error' ? 'field-err' : 'ld-muted'} role={message.tone === 'error' ? 'alert' : 'status'}>
          {message.tone === 'error' && <span aria-hidden="true">⚠ </span>}{message.text}
        </span>
      )}
    </>
  );
}
