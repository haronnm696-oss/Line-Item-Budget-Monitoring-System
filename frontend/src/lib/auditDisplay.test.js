import test from 'node:test';
import assert from 'node:assert/strict';
import { isInternalChange, isPhotoEcho, projectCode, recordTypeLine, visibleChanges, visibleEntries } from './auditDisplay.js';

const leader = { id: 1, name: 'Dr. Aminah Macarambon', role: 'Program Leader' };

test('the record type line is short: approval kind, no person role', () => {
  assert.equal(recordTypeLine('Approval request · Liquidation'), 'Liquidation');
  assert.equal(recordTypeLine('Approval request · Realignment'), 'Realignment');
  assert.equal(recordTypeLine('Approval request'), 'Approval request');
  assert.equal(recordTypeLine('User account · Program Leader'), 'User account');
  assert.equal(recordTypeLine('Project assignment · Researcher'), 'Project assignment');
  assert.equal(recordTypeLine('Expense · Supplies'), 'Expense · Supplies');
});

test('the project column uses the code from the page options, or nothing', () => {
  const options = [{ id: 7, title: 'Smart farms', code: 'RP-2026-021' }];
  assert.equal(projectCode({ id: 7, title: 'Smart farms' }, options), 'RP-2026-021');
  assert.equal(projectCode({ id: 8, title: 'Other' }, options), null);
  assert.equal(projectCode(null, options), null);
});

test('rule 5: "Reviewed by: — → <name>" is hidden only when <name> is the row user', () => {
  const entry = {
    actor: leader,
    action: { code: 'approved', label: 'Request approved' },
    changes: [
      { label: 'Reviewed by', old: '—', new: leader.name },
      { label: 'Amount', old: '₱1,000.00', new: '₱2,000.00' },
    ],
  };
  assert.deepEqual(visibleChanges(entry).map((c) => c.label), ['Amount']);
  // Someone else decided: the line stays.
  const other = { ...entry, changes: [{ label: 'Reviewed by', old: '—', new: 'Someone Else' }] };
  assert.equal(visibleChanges(other).length, 1);
  // A real change of reviewer (old not empty) stays.
  const changed = { ...entry, changes: [{ label: 'Reviewed by', old: 'Someone Else', new: leader.name }] };
  assert.equal(visibleChanges(changed).length, 1);
});

test('rule 5: a status the action badge already states is hidden; other statuses stay', () => {
  const approved = { actor: leader, action: { code: 'approved', label: 'Request approved' }, changes: [{ label: 'Status', old: 'Pending', new: 'Approved' }] };
  assert.deepEqual(visibleChanges(approved), []);
  const deactivated = { actor: leader, action: { code: 'deactivated', label: 'Account deactivated' }, changes: [{ label: 'Account', old: 'Active', new: 'Inactive' }] };
  assert.deepEqual(visibleChanges(deactivated), []);
  // An edit that changes the status says something new.
  const edited = { actor: leader, action: { code: 'updated', label: 'Project edited' }, changes: [{ label: 'Status', old: 'Ongoing', new: 'Completed' }] };
  assert.equal(visibleChanges(edited).length, 1);
  // A different outcome than the badge stays.
  const odd = { actor: leader, action: { code: 'approved', label: 'Request approved' }, changes: [{ label: 'Status', old: 'Pending', new: 'Released' }] };
  assert.equal(visibleChanges(odd).length, 1);
});

test('rule 5: internal fields are hidden; real fields such as Employee ID stay', () => {
  for (const label of ['Avatar path', 'Updated at', 'Remember token', 'Purchase request id', 'Hash']) {
    assert.equal(isInternalChange({ label }), true, label);
  }
  for (const label of ['Employee ID', 'Name', 'Amount', 'Status', 'Line item code', 'Avatar']) {
    assert.equal(isInternalChange({ label }), false, label);
  }
  const entry = { actor: leader, action: { code: 'updated' }, changes: [{ label: 'Avatar path', old: null, new: 'avatars/a.jpg' }, { label: 'Name', old: 'A', new: 'B' }] };
  assert.deepEqual(visibleChanges(entry).map((c) => c.label), ['Name']);
});

function account(id, extra) {
  return { record: { label: 'Norjannah', type: 'User account · Researcher', link: { kind: 'user', user_id: id } }, actor: leader, ...extra };
}

test('rule 6: an avatar-only account edit next to its profile photo entry is not shown', () => {
  const photo = account(5, { id: 2, created_at: '2026-11-26T08:00:20+08:00', action: { code: 'Profile photo updated', label: 'Profile photo updated' }, changes: [{ label: 'Avatar', old: 'none', new: 'photo' }] });
  const echo = account(5, { id: 1, created_at: '2026-11-26T08:00:19+08:00', action: { code: 'updated', label: 'User account edited' }, changes: [{ label: 'Avatar path', old: null, new: 'avatars/x.jpg' }] });
  const entries = [photo, echo];
  assert.equal(isPhotoEcho(echo, entries), true);
  assert.deepEqual(visibleEntries(entries).map((e) => e.id), [2]);
  // The list given to the page is not changed (the API and count keep it).
  assert.equal(entries.length, 2);
});

test('rule 6: the account edit stays when it changes a real field, is another account, or is not within a minute', () => {
  const photo = account(5, { id: 2, created_at: '2026-11-26T08:00:20+08:00', action: { code: 'Profile photo updated', label: 'Profile photo updated' }, changes: [] });
  const withName = account(5, { id: 1, created_at: '2026-11-26T08:00:19+08:00', action: { code: 'updated' }, changes: [{ label: 'Avatar path', old: null, new: 'avatars/x.jpg' }, { label: 'Name', old: 'A', new: 'B' }] });
  const otherUser = account(6, { id: 3, created_at: '2026-11-26T08:00:19+08:00', action: { code: 'updated' }, changes: [{ label: 'Avatar path', old: null, new: 'avatars/y.jpg' }] });
  const later = account(5, { id: 4, created_at: '2026-11-26T08:05:00+08:00', action: { code: 'updated' }, changes: [{ label: 'Avatar path', old: null, new: 'avatars/z.jpg' }] });
  const entries = [photo, withName, otherUser, later];
  assert.deepEqual(visibleEntries(entries).map((e) => e.id), [2, 1, 3, 4]);
  // Without a photo entry nothing is hidden.
  assert.deepEqual(visibleEntries([later]).map((e) => e.id), [4]);
});
