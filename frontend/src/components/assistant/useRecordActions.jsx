import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ENDPOINTS } from '../../api/endpoints';
import { apiWrite } from '../../api/http';
import { errorText } from '../../api/leader';
import { fetchMyItem } from '../../api/assistant';
import { useRoleBadges } from '../../layouts/roleBadges';
import { peso } from '../money';
import RecordExpenseDialog from '../leader/RecordExpenseDialog';
import ConfirmDialog from './ConfirmDialog';
import PurchaseRequestDialog from './PurchaseRequestDialog';
import UploadDialog from './UploadDialog';

const SUBMIT_URL = {
  expense: ENDPOINTS.submitExpense,
  purchase_request: (id) => ENDPOINTS.purchaseRequestStep(id, 'submit'),
  payroll: (id) => ENDPOINTS.payrollBatchStep(id, 'submit'),
};

const DELETE_URL = {
  expense: ENDPOINTS.expense,
  purchase_request: ENDPOINTS.purchaseRequest,
};

const UPLOAD_TYPES = {
  expense: ['receipt', 'invoice', 'other'],
  purchase_request: ['quotation', 'invoice', 'other'],
  payroll: ['payroll_file', 'other'],
};

function what(item) {
  return `${item.reference ?? item.type_label} “${item.title}”${item.amount != null ? ` (${peso(item.amount)})` : ''}`;
}

/**
 * Actions on the Research Assistant's own records, through the existing
 * workflow routes: submit, delete (own draft / returned expense, own draft
 * request), cancel an approved request (reason), upload a document, and
 * edit (expense / purchase request dialogs; payroll opens its month).
 * `onChanged` reloads the page's data; the sidebar badge is refreshed too.
 */
export function useRecordActions({ projectId, onChanged }) {
  const navigate = useNavigate();
  const { refreshBadges } = useRoleBadges();
  const [feedback, setFeedback] = useState(null);
  const [busyKey, setBusyKey] = useState(null);
  const [modal, setModal] = useState(null); // { kind, item, error? }

  function changed(text, tone = 'ok') {
    setFeedback({ tone, text });
    refreshBadges();
    onChanged?.();
  }

  async function submit(item) {
    setFeedback(null);
    setBusyKey(item.key);
    try {
      await apiWrite('post', SUBMIT_URL[item.type](item.id));
      changed(`Submitted ${what(item)}. It is waiting for the Project Leader.`);
    } catch (error) {
      setFeedback({ tone: 'error', text: errorText(error, 'Could not submit. Please try again.') });
    } finally {
      setBusyKey(null);
    }
  }

  async function confirm(reason) {
    const { kind, item } = modal;
    setBusyKey(item.key);
    try {
      if (kind === 'delete') {
        await apiWrite('delete', DELETE_URL[item.type](item.id));
        setModal(null);
        changed(`Deleted ${what(item)}.`);
      } else if (kind === 'cancel') {
        await apiWrite('post', ENDPOINTS.purchaseRequestStep(item.id, 'cancel'), { reason });
        setModal(null);
        changed(`Cancelled ${what(item)}. Its estimated amount is no longer held on the line item.`);
      }
    } catch (error) {
      setModal({ ...modal, error: errorText(error, 'Could not save. Please try again.') });
    } finally {
      setBusyKey(null);
    }
  }

  function edit(item) {
    setFeedback(null);
    if (item.type === 'payroll') {
      navigate(`/payroll?project=${projectId}${item.month ? `&month=${item.month}` : ''}`);
      return;
    }
    setModal({ kind: 'edit', item });
  }

  // "Edit and resubmit" from a list row: list rows carry no edit fields, so
  // load the full record first (payroll opens its month instead).
  async function resubmit(item) {
    setFeedback(null);
    if (item.type === 'payroll') {
      edit(item);
      return;
    }
    setBusyKey(item.key);
    try {
      const data = await fetchMyItem(projectId, item.type, item.id);
      if (data?.item?.can_edit) setModal({ kind: 'edit', item: data.item });
      else setFeedback({ tone: 'error', text: 'This record can no longer be edited.' });
    } catch (error) {
      setFeedback({ tone: 'error', text: errorText(error, 'Could not load this record. Please try again.') });
    } finally {
      setBusyKey(null);
    }
  }

  const close = () => setModal(null);
  const done = (text) => { setModal(null); changed(text); };
  const busy = Boolean(modal && busyKey === modal.item.key);

  let dialog = null;
  if (modal?.kind === 'delete') {
    dialog = (
      <ConfirmDialog
        title={`Delete this ${modal.item.type_label.toLowerCase()}?`}
        body={`${what(modal.item)} and its documents are removed from your submissions. This cannot be undone.`}
        confirmLabel="Delete"
        busyLabel="Deleting…"
        danger
        busy={busy}
        error={modal.error}
        onClose={close}
        onConfirm={confirm}
      />
    );
  } else if (modal?.kind === 'cancel') {
    dialog = (
      <ConfirmDialog
        title="Cancel this purchase request?"
        body={`${what(modal.item)} was approved but not fulfilled. The Project Leader is notified.`}
        confirmLabel="Cancel request"
        busyLabel="Cancelling…"
        danger
        reasonLabel="Reason"
        busy={busy}
        error={modal.error}
        onClose={close}
        onConfirm={confirm}
      />
    );
  } else if (modal?.kind === 'upload') {
    dialog = (
      <UploadDialog
        record={modal.item}
        types={modal.types ?? UPLOAD_TYPES[modal.item.type]}
        title={modal.title}
        onClose={close}
        onDone={done}
      />
    );
  } else if (modal?.kind === 'edit' && modal.item.type === 'expense') {
    dialog = <RecordExpenseDialog assistant projectId={projectId} expense={modal.item} onClose={() => { close(); onChanged?.(); }} onDone={done} />;
  } else if (modal?.kind === 'edit' && modal.item.type === 'purchase_request') {
    dialog = <PurchaseRequestDialog projectId={projectId} request={modal.item} onClose={() => { close(); onChanged?.(); }} onDone={done} />;
  }

  return {
    feedback,
    setFeedback,
    busyKey,
    dialog,
    submit,
    edit,
    resubmit,
    remove: (item) => { setFeedback(null); setModal({ kind: 'delete', item }); },
    cancel: (item) => { setFeedback(null); setModal({ kind: 'cancel', item }); },
    upload: (item, options = {}) => { setFeedback(null); setModal({ kind: 'upload', item, ...options }); },
  };
}
