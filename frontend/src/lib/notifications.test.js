import assert from 'node:assert/strict';
import test from 'node:test';

import { notificationTarget, unreadBadge } from './notifications.js';

const LINK = '/expenses?project=4&expense=17';

test('a role that may open the link goes straight to it', () => {
  assert.equal(notificationTarget('RA', LINK), LINK);
  // Finance/Admin has no Expenses page (it verifies, it does not encode):
  // the Verification queue of the same project.
  assert.equal(notificationTarget('FIN', LINK), '/verification-queue?project=4');
});

test('other roles go to their review page with the same project', () => {
  assert.equal(notificationTarget('PL', LINK), '/submissions?project=4');
  assert.equal(notificationTarget('PRG', LINK), '/program-approvals?project=4');
});

test('a missing or foreign link falls back to the review page', () => {
  assert.equal(notificationTarget('PL', null), '/submissions');
  assert.equal(notificationTarget('RA', 'https://example.com'), '/my-submissions');
});

test('a Project Leader opens the record each notification is about', () => {
  assert.equal(
    notificationTarget('PL', LINK, { type: 'expense', id: 17 }, 4),
    '/submissions?project=4&open=expense-17'
  );
  assert.equal(
    notificationTarget('PL', '/payroll?project=4&month=2026-11', { type: 'payroll', id: 9 }, 4),
    '/submissions?project=4&open=payroll-9'
  );
  assert.equal(
    notificationTarget('PL', '/purchase-requests?project=4&request=3', { type: 'purchase_request', id: 3 }),
    '/submissions?project=4&open=purchase_request-3'
  );
  assert.equal(
    notificationTarget('PL', '/my-requests?project=4', { type: 'approval', id: 21 }, 4),
    '/my-requests?project=4&request=21'
  );
  // A record type the role has no panel for keeps the notification's own
  // link when the role may open it, else its review page.
  assert.equal(notificationTarget('RA', '/payroll?project=4', { type: 'approval', id: 17 }, 4), '/payroll?project=4');
  assert.equal(notificationTarget('FIN', LINK, { type: 'approval', id: 17 }, 4), '/verification-queue?project=4');
});

test('badge text is capped', () => {
  assert.equal(unreadBadge(0), '');
  assert.equal(unreadBadge(7), '7');
  assert.equal(unreadBadge(140), '99+');
});

test('a Research Assistant opens the exact record on its own page and panel', () => {
  assert.equal(
    notificationTarget('RA', LINK, { type: 'expense', id: 17 }, 4),
    '/expenses?project=4&open=expense-17',
  );
  assert.equal(
    notificationTarget('RA', '/purchase-requests?project=4&request=9', { type: 'purchase_request', id: 9 }, 4),
    '/purchase-requests?project=4&open=purchase_request-9',
  );
  assert.equal(
    notificationTarget('RA', '/payroll?project=4&month=2026-11', { type: 'payroll', id: 3 }, 4),
    '/payroll?project=4&month=2026-11&open=payroll-3',
  );
  // No record: the link itself (or the role's page).
  assert.equal(notificationTarget('RA', LINK), LINK);
});

test('a Finance/Admin opens the record in the Verification queue panel', () => {
  assert.equal(
    notificationTarget('FIN', LINK, { type: 'expense', id: 17 }, 4),
    '/verification-queue?project=4&open=expense-17',
  );
  assert.equal(
    notificationTarget('FIN', '/payroll?project=4&month=2026-11', { type: 'payroll', id: 3 }, 4),
    '/verification-queue?project=4&open=payroll-3',
  );
});

test('a Program Leader opens a new account request in its Review panel', () => {
  assert.equal(
    notificationTarget('PRG', '/program-users?review=42', { type: 'registration', id: 42 }),
    '/program-users?review=42',
  );
  // Other roles never get that panel.
  assert.notEqual(notificationTarget('FIN', '/program-users?review=42', { type: 'registration', id: 42 }), '/program-users?review=42');
});
