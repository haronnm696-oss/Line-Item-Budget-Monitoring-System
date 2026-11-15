import assert from 'node:assert/strict';
import test from 'node:test';

import { notificationTarget, unreadBadge } from './notifications.js';

const LINK = '/expenses?project=4&expense=17';

test('a role that may open the link goes straight to it', () => {
  assert.equal(notificationTarget('RA', LINK), LINK);
  assert.equal(notificationTarget('FIN', LINK), LINK);
});

test('other roles go to their review page with the same project', () => {
  assert.equal(notificationTarget('PL', LINK), '/submissions?project=4');
  assert.equal(notificationTarget('PRG', LINK), '/program-approvals?project=4');
});

test('a missing or foreign link falls back to the review page', () => {
  assert.equal(notificationTarget('PL', null), '/submissions');
  assert.equal(notificationTarget('RA', 'https://example.com'), '/my-submissions');
});

test('badge text is capped', () => {
  assert.equal(unreadBadge(0), '');
  assert.equal(unreadBadge(7), '7');
  assert.equal(unreadBadge(140), '99+');
});
