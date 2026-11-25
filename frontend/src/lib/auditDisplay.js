// On-screen display rules for the Program Leader's Audit trail
// (pages/program/ProgramAudit.jsx). Display only: the API
// (AuditEntryPresenter, shared with the project page's Recent activity) and
// the stored entries are unchanged; these rules only decide what the list
// on screen repeats. Kept free of JSX so node:test can check them.

const EMPTY = new Set([null, undefined, '', '—']);

/**
 * The short type line under a record: "Approval request · Liquidation" ->
 * "Liquidation"; "User account · Program Leader" -> "User account" (the
 * role is the person's, not the record's). Other types stay as they are.
 */
export function recordTypeLine(type) {
  const text = String(type ?? '');
  const [noun, ...rest] = text.split(' · ');
  if (noun === 'Approval request') return rest.join(' · ') || noun;
  if (noun === 'User account' || noun === 'Project assignment') return noun;
  return text;
}

/** The project's code from the page's project options (the entry carries only id and title). */
export function projectCode(project, options = []) {
  if (!project) return null;
  return options.find((item) => String(item.id) === String(project.id))?.code || null;
}

/**
 * Fields that mean nothing to a reader: storage paths, ids, hashes,
 * tokens, timestamps. Labels come from the presenter (unknown keys are
 * humanized, e.g. avatar_path -> "Avatar path"). "Employee ID" is a real
 * field (upper-case "ID"), so only a lower-case " id" ending counts.
 */
const INTERNAL_LABELS = new Set(['Id', 'Uuid', 'Hash', 'Password', 'Remember token', 'Avatar path', 'Created at', 'Updated at', 'Deleted at']);

export function isInternalChange(change) {
  const label = String(change?.label ?? '');
  return INTERNAL_LABELS.has(label) || / (id|uuid|hash|token|path)$/.test(label);
}

/** Who-decided fields: "— -> <name>" repeats the row's User when <name> is that user. */
const DECIDER_LABELS = new Set(['Reviewed by', 'Verified by', 'Approved by (Project Leader)']);

/** Field label and new value an action badge already states ("Request approved" -> Status: Approved). */
const OUTCOME = {
  approved: [['Status', 'approved'], ['Stage', 'approved']],
  rejected: [['Status', 'rejected'], ['Stage', 'rejected']],
  returned: [['Status', 'returned'], ['Stage', 'returned']],
  withdrawn: [['Status', 'withdrawn'], ['Stage', 'withdrawn']],
  verified: [['Status', 'verified'], ['Stage', 'verified']],
  fulfilled: [['Status', 'fulfilled'], ['Stage', 'fulfilled']],
  cancelled: [['Status', 'cancelled'], ['Stage', 'cancelled']],
  activated: [['Account', 'active']],
  deactivated: [['Account', 'inactive']],
};

function repeatsDecider(change, entry) {
  return DECIDER_LABELS.has(change.label)
    && EMPTY.has(change.old)
    && Boolean(entry.actor?.name)
    && change.new === entry.actor.name;
}

function repeatsOutcome(change, entry) {
  const outcomes = OUTCOME[entry.action?.code] ?? [];
  const value = String(change.new ?? '').trim().toLowerCase();
  return outcomes.some(([label, outcome]) => change.label === label && value === outcome);
}

/** The change lines worth showing: no internal fields, no repeat of the User or the action badge. */
export function visibleChanges(entry) {
  return (entry?.changes ?? []).filter((change) => !isInternalChange(change)
    && !repeatsDecider(change, entry)
    && !repeatsOutcome(change, entry));
}

const PHOTO_ACTIONS = new Set(['Profile photo updated', 'Profile photo removed']);
const SAME_MINUTE_MS = 60 * 1000;

function accountId(entry) {
  const link = entry?.record?.link;
  return link?.kind === 'user' ? String(link.user_id) : null;
}

/**
 * A "User account edited" entry whose only changes are internal fields
 * (e.g. the avatar path) next to a profile photo entry for the same
 * account within a minute: the photo entry already says it.
 */
export function isPhotoEcho(entry, entries) {
  if (entry?.action?.code !== 'updated' || !String(entry?.record?.type ?? '').startsWith('User account')) return false;
  const changes = entry.changes ?? [];
  if (changes.length === 0 || !changes.every(isInternalChange)) return false;
  const account = accountId(entry);
  const at = Date.parse(entry.created_at);
  if (!account || Number.isNaN(at)) return false;
  return entries.some((other) => other !== entry
    && (PHOTO_ACTIONS.has(other.action?.code) || PHOTO_ACTIONS.has(other.action?.label))
    && accountId(other) === account
    && Math.abs(Date.parse(other.created_at) - at) < SAME_MINUTE_MS);
}

/** The entries shown on screen (the API, the database and the total count keep them all). */
export function visibleEntries(entries = []) {
  return entries.filter((entry) => !isPhotoEcho(entry, entries));
}
