import { useState } from 'react';
import { decideItem, errorText } from '../../api/leader';
import ReturnDialog from './ReturnDialog';
import { useRoleBadges } from '../../layouts/roleBadges';

const NEXT = {
  expense: 'Finance/Admin will verify it next.',
  payroll: 'It goes to verification next.',
  purchase_request: 'The requester was told it is approved.',
};

/**
 * Approve / Return for queue records, through the existing workflow routes.
 * `onDone()` reloads the page data after a decision. Returns the busy key,
 * the feedback banner, handlers, and the Return dialog element to render.
 */
export function useReviewActions(onDone) {
  const { refreshBadges } = useRoleBadges();
  const [busyKey, setBusyKey] = useState(null);
  const [feedback, setFeedback] = useState(null); // { tone, text }
  const [returning, setReturning] = useState(null);
  const [returnBusy, setReturnBusy] = useState(false);
  const [returnError, setReturnError] = useState('');

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

  const dialog = (
    <ReturnDialog
      key={returning?.key ?? 'closed'}
      item={returning}
      busy={returnBusy}
      error={returnError}
      onClose={() => { if (!returnBusy) setReturning(null); }}
      onConfirm={confirmReturn}
    />
  );

  return { busyKey, feedback, setFeedback, approve, openReturn, dialog };
}
