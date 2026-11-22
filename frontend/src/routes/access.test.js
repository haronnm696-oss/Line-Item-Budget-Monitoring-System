import assert from 'node:assert/strict';
import test from 'node:test';

import { ROUTE_BY_ROLE, ROUTE_ROLES, canOpen, homeFor, rolesFor } from './access.js';
import { COMING_SOON_PATHS, ROLE_NAV } from '../layouts/roleNav.js';

const ROLES = ['PRG', 'PL', 'RA', 'FIN', 'RES'];

test('every role can open every item in its own menu', () => {
  for (const role of ROLES) {
    for (const item of ROLE_NAV[role].flatMap((group) => group.items)) {
      assert.ok(canOpen(role, item.to), `${role} -> ${item.to}`);
    }
  }
});

test('every role lands on a dashboard it may open', () => {
  for (const role of ROLES) {
    assert.ok(canOpen(role, ROUTE_BY_ROLE[role]), role);
    assert.equal(homeFor(role), ROUTE_BY_ROLE[role]);
  }
});

test('every guarded route is in the table (no route open to nobody)', () => {
  for (const path of COMING_SOON_PATHS) {
    assert.ok(rolesFor(path).length > 0, path);
  }
});

test("a role cannot open another role's pages", () => {
  const denied = {
    PRG: ['/dashboard/project-leader', '/expenses', '/payroll', '/reports', '/verification', '/projects', '/audit'],
    PL: ['/program-dashboard', '/program-users', '/expenses', '/payroll', '/verification', '/verification-queue', '/purchase-requests', '/dashboard/finance'],
    RA: ['/program-approvals', '/reports', '/audit', '/projects', '/budget', '/submissions', '/verification', '/verification-queue', '/transaction-history', '/dashboard/researcher'],
    FIN: ['/program-audit', '/submissions', '/my-requests', '/documents', '/budget', '/dashboard/project-leader'],
    RES: ['/program-reports', '/expenses', '/payroll', '/audit', '/submissions', '/verification', '/verification-queue', '/transaction-history', '/my-submissions', '/purchase-requests', '/documents'],
  };
  for (const [role, paths] of Object.entries(denied)) {
    for (const path of paths) {
      assert.equal(canOpen(role, path), false, `${role} must not open ${path}`);
    }
  }
});

test('patterns match ids and ignore the query string; unknown paths are denied', () => {
  assert.ok(canOpen('PL', '/projects/12'));
  assert.ok(canOpen('PRG', '/program-projects/3?tab=team'));
  assert.ok(canOpen('RA', '/expenses?project=4'));
  assert.equal(canOpen('PL', '/projects/12/extra'), false);
  assert.deepEqual(rolesFor('/no-such-page'), []);
});

test('the table only uses known roles', () => {
  for (const [path, roles] of Object.entries(ROUTE_ROLES)) {
    assert.ok(roles.length > 0, path);
    for (const role of roles) assert.ok(ROLES.includes(role), `${path}: ${role}`);
  }
});
