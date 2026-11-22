import { useState } from 'react';
import { decideItem, errorText } from '../../api/leader';
import { ENDPOINTS } from '../../api/endpoints';
import { apiWrite } from '../../api/http';
import ReturnDialog from './ReturnDialog';
import ConfirmDialog from '../assistant/ConfirmDialog';
import { peso } from '../money';
import { useRoleBadges } from '../../layouts/roleBadges';

const NEXT = {
  expense: 'Finance/Admin will verify it next.',
  payroll: 'It goes to verification next.',
  purchase_request: 'The requester was told it is approved.',
};

/**
 * Approve / Return for queue records, through the existing workflow routes,
 * and Cancel request (reason required) for an approved or partly fulfilled
 * purchase request, offered in the review panel only (POST
 * /purchase-requests/:id/cancel). `onDone()` reloads the page data after a
 * decision. Returns the busy key, the feedback banner, handlers, and the
 * dialog elements to render.
 */
export function useReviewActions(onDone) {
  const { refreshBadges } = useRoleBadges();
  const [busyKey, setBusyKey] = useState(null);
  const [feedback, setFeedback] = useState(null); // { tone, text }
  const [returning, setReturning] = useState(null);
  const [returnBusy, setReturnBusy] = useState(false);
  const [returnError, setReturnError] = useState('');
  const [cancelling, setCancelling] = useState(null); // { item, error? }
  const [cancelBusy, setCancelBusy] = useState(false);

  function openCancel(item) {
    setFeedback(null);
    setCancelling({ item });
  }

  async function confirmCancel(reason) {
    if (!cancelling || cancelBusy) return;
    const { item } = cancelling;
    setCancelBusy(true);
    try {
      await apiWrite('post', ENDPOINTS.purchaseRequestStep(item.id, 'cancel'), { reason });
      const partly = item.fulfilment && item.fulfilment !== 'none';
      setFeedback({
        tone: 'ok',
        text: `Cancelled “${item.title}” with your reason. ${partly
          ? 'Its open items are no longer held on their line items; items already linked stay with their expenses.'
          : 'Its estimated amount is no longer held on the line items.'}`,
      });
      setCancelling(null);
      refreshBadges();
      await onDone?.();
    } catch (error) {
      setCancelling({ item, error: errorText(error, 'Could not cancel it. Please try again.') });
    } finally {
      setCancelBusy(false);
    }
  }

  async function approve(item) {
    if (busyKey) return;
    setBusyKey(item.key);
    setFeedback(null);
    try {
      await decideItem(item, 'approve');
      setFeedback({ tone: 'ok', text: `Approved “${item.title}”. ${NEXT[item.type] ?? ''}`.trim() });
      refreshBadges();
      await onDone?.();
    } catch (error) {
      setFeedback({ tone: 'error', text: errorText(error, 'Could not approve. Please try again.') });
    } finally {
      setBusyKey(null);
    }
  }

  function openReturn(item) {
    setReturnError('');
    setReturning(item);
  }

  async function confirmReturn(reason) {
    if (!returning || returnBusy) return;
    setReturnBusy(true);
    setReturnError('');
    try {
      await decideItem(returning, 'return', reason);
      const who = returning.submitted_by?.name;
      setFeedback({ tone: 'ok', text: `Returned “${returning.title}”${who ? ` to ${who}` : ''} with your reason.` });
      setReturning(null);
      refreshBadges();
      await onDone?.();
    } catch (error) {
      setReturnError(errorText(error, 'Could not return it. Please try again.'));
    } finally {
      setReturnBusy(false);
    }
  }

  const cancelItem = cancelling?.item;
  const dialog = (
    <>
      <ReturnDialog
        key={returning?.key ?? 'closed'}
        item={returning}
        busy={returnBusy}
        error={returnError}
        onClose={() => { if (!returnBusy) setReturning(null); }}
        onConfirm={confirmReturn}
      />
      {cancelItem && (
        <ConfirmDialog
          key={cancelItem.key}
          title="Cancel this purchase request?"
          body={`“${cancelItem.title}”${cancelItem.amount != null ? ` (${peso(cancelItem.amount)})` : ''} ${cancelItem.fulfilment && cancelItem.fulfilment !== 'none'
            ? 'is partly fulfilled: only its open items are released; items already linked stay with their expenses.'
            : 'is approved: its estimated amount stops being held on the line items.'} The requester is notified with your reason.`}
          confirmLabel="Cancel request"
          busyLabel="Cancelling…"
          danger
          reasonLabel="Reason"
          busy={cancelBusy}
          error={cancelling.error}
          onClose={() => { if (!cancelBusy) setCancelling(null); }}
          onConfirm={confirmCancel}
        />
      )}
    </>
  );

  return { busyKey, feedback, setFeedback, approve, openReturn, openCancel, dialog };
}
