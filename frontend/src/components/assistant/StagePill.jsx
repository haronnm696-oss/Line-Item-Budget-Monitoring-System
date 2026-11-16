import ProgramPill from '../program/ProgramPill';
import { stagePill } from '../../lib/assistantStatus';

/** A record's stage as text plus an icon (Draft, Submitted, Approved, Returned, …). */
export default function StagePill({ status, type = null }) {
  const pill = stagePill(status, type);
  return <ProgramPill value={pill.label} tone={pill.tone} icon={pill.icon} />;
}
