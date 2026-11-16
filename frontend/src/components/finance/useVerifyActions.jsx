import { useState } from 'react';
import { ENDPOINTS } from '../../api/endpoints';
import { apiWrite } from '../../api/http';
import { errorText } from '../../api/leader';
import { useRoleBadges } from '../../layouts/roleBadges';
import { peso } from '../money';
import ConfirmDialog from '../assistant/ConfirmDialog';
import RecordExpenseDialog from '../leader/RecordExpenseDialog';

const STEP_URL = {
  expense: (id, step) => (step === 'verify' ? ENDPOINTS.verifyExpense(id) : ENDPOINTS.rejectExpense(id)),
  payroll: (id, step) => ENDPOINTS.payrollBatchStep(id, step),
};

function what(item) {
  return `${item.reference} “${item.title}”${item.amount != null ? ` (${peso(item.amount)})` : ''}`;
}

/**
 * Finance/Admin actions through the existing workflow routes: Verify,
 * Reject (reason required, sent back to the encoder), Notify RA about a
 * missing receipt, and Edit and resubmit an expense they recorded.
 * `onChanged` reloads the page; the sidebar badge is refreshed too.
 */
export function useVerifyActions({ projectId, onChanged }) {
  const { refreshBadges } = useRoleBadges();
  const [feedback, setFeedback] = useState(null);
  const [busyKey, setBusyKey] = useState(null);
  const [modal, setModal] = useState(null); // { kind: 'reject' | 'edit', item, error? }

  function changed(text) {
    setFeedback({ tone: 'ok', text });
    refreshBadges();
    onChanged?.();
  }

  async function verify(item) {
    setFeedback(null);
    setBusyKey(item.key);
    try {
      await apiWrite('post', STEP_URL[item.type](item.id, 'verify'));
      changed(item.type === 'expense'
        ? `Verified ${what(item)}. It is now counted in Total spent.`
        : `Verified ${what(item)}. Payroll is not counted in LIB spent.`);
    } catch (error) {
      setFeedback({ tone: 'error', text: errorText(error, 'Could not verify. Please try again.') });
    } finally {
      setBusyKey(null);
    }
  }

  async function reject(reason) {
    const { item } = modal;
    setBusyKey(item.key);
    try {
      await apiWrite('post', STEP_URL[item.type](item.id, 'reject'), { reason });
      setModal(null);
      changed(`Rejected ${what(item)}. It went back to ${item.submitted_by?.name ?? 'the encoder'} with your reason.`);
    } catch (error) {
      setModal({ ...modal, error: errorText(error, 'Could not reject. Please try again.') });
    } finally {
      setBusyKey(null);
    }
  }

  async function notifyRa(row) {
    const key = `notify-${row.id}`;
    setFeedback(null);
    setBusyKey(key);
    try {
      const data = await apiWrite('post', ENDPOINTS.requestReceipt(row.id));
      setFeedback({ tone: 'ok', text: data?.message ?? 'Notified.' });
    } catch (error) {
      setFeedback({ tone: 'error', text: errorText(error, 'Could not send the notification. Please try again.') });
    } finally {
      setBusyKey(null);
    }
  }

  let dialog = null;
  if (modal?.kind === 'reject') {
    dialog = (
      <ConfirmDialog
        title={`Reject this ${modal.item.type_label.toLowerCase()}?`}
        body={`${what(modal.item)} goes back to ${modal.item.submitted_by?.name ?? 'the encoder'} with your reason.`}
        confirmLabel="Reject"
        busyLabel="Rejecting…"
        danger
        reasonLabel="Reason"
        busy={busyKey === modal.item.key}
        error={modal.error}
        onClose={() => setModal(null)}
        onConfirm={reject}
      />
    );
  } else if (modal?.kind === 'edit') {
    dialog = (
      <RecordExpenseDialog
        assistant
        projectId={projectId}
        expense={modal.item}
        onClose={() => { setModal(null); onChanged?.(); }}
        onDone={(text) => { setModal(null); changed(text); }}
      />
    );
  }

  return {
    feedback,
    setFeedback,
    busyKey,
    dialog,
    verify,
    notifyRa,
    reject: (item) => { setFeedback(null); setModal({ kind: 'reject', item }); },
    edit: (item) => { setFeedback(null); setModal({ kind: 'edit', item }); },
  };
}
