import ProgramPill from '../program/ProgramPill';
import { stagePill } from '../../lib/assistantStatus';

/**
 * A record's stage as text plus an icon (Draft, Submitted, Approved, Returned, …).
 * A purchase request also takes `fulfilment` (Partly fulfilled, Fulfilled).
 */
export default function StagePill({ status, type = null, fulfilment = null }) {
  const pill = stagePill(status, type, fulfilment);
  return <ProgramPill value={pill.label} tone={pill.tone} icon={pill.icon} />;
}
