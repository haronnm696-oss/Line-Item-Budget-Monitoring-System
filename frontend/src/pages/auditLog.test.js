import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

import { PAGE_NAMES, ROLE_NAV } from '../layouts/roleNav.js';
import { SEARCH_DEBOUNCE_MS, nextExpanded, recordLine, rowClickToggles, searchParam } from '../lib/auditLogList.js';

// No JSX renderer is set up for node:test, so the components are checked by source.
const SRC = fileURLToPath(new URL('..', import.meta.url));
const source = (path) => readFileSync(join(SRC, path), 'utf8');

function sourceFiles(dir = SRC) {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) return sourceFiles(path);
    return /\.(jsx?|tsx?|css)$/.test(name) && !/\.test\.js$/.test(name) ? [path] : [];
  });
}

const list = source('components/AuditLogList.jsx');
const program = source('pages/program/ProgramAudit.jsx');
const leader = source('pages/AuditTrail.jsx');

// ------------------------------------------------------------------ rename

test('no "Audit trail" wording is left anywhere in the frontend', () => {
  for (const file of sourceFiles()) {
    assert.doesNotMatch(readFileSync(file, 'utf8'), /audit[ -]trail/i, file.slice(SRC.length));
  }
});

test('the sidebar, page titles, links and tab title say "Audit log"; old URLs stay', () => {
  const item = ROLE_NAV.PRG.flatMap((group) => group.items).find((entry) => entry.to === '/program-audit');
  assert.equal(item.label, 'Audit log');
  assert.equal(PAGE_NAMES['/audit'], 'Audit log');
  assert.match(source('layouts/RoleShell.jsx'), /PAGE_NAMES\[location\.pathname\]/);
  assert.match(program, /<h1 className="program-h1">Audit log<\/h1>/);
  assert.match(leader, /<h2>Audit log<\/h2>/);
  assert.match(leader, /\{ title: 'Audit log' \}/);
  assert.match(source('pages/program/ProgramProjectDetail.jsx'), />Audit log<\/Link>/);
  assert.match(source('pages/program/ProgramProjects.jsx'), /'View audit log'/);
  assert.match(source('components/program/ProgramUserManage.jsx'), /View in audit log/);
  const app = source('App.jsx');
  assert.match(app, /path="\/program-audit"/);
  assert.match(app, /\['\/audit', <AuditTrail \/>\]/);
});

// ------------------------------------------------------------ compact list

test('only one entry is open at a time; clicking the open one closes it', () => {
  assert.equal(nextExpanded(null, 7), 7);
  assert.equal(nextExpanded(7, 9), 9);
  assert.equal(nextExpanded(9, 9), null);
});

test('a click on a link or button inside a row does not toggle it', () => {
  const at = (selectorHit) => ({ closest: (selector) => (selector.includes(selectorHit) ? {} : null) });
  assert.equal(rowClickToggles({ closest: () => null }), true);
  assert.equal(rowClickToggles(at('a')), false);
  assert.equal(rowClickToggles(at('button')), false);
});

test('the record reads "name · short type" on one line', () => {
  assert.equal(recordLine('Realign to field travel', 'Realignment'), 'Realign to field travel · Realignment');
  assert.equal(recordLine('Release 1', ''), 'Release 1');
  assert.equal(recordLine(null, 'Project'), 'Project');
});

test('the list rows expand by a keyboard-reachable toggle and show details full width', () => {
  assert.match(list, /<button\s+type="button"\s+className="au-toggle"/);
  assert.match(list, /aria-expanded=\{open\}/);
  assert.match(list, /aria-controls=\{detailsId\}/);
  assert.match(list, /<tr className="au-detail" id=\{detailsId\} hidden=\{!open\}>/);
  assert.match(list, /<td colSpan=\{6\}>/);
  assert.match(list, /rowClickToggles\(event\.target\)/);
  assert.match(list, /formatShortDateTime\(row\.time\)/);
  // Columns: Time, User, Action, Record, Project (no Change column).
  assert.doesNotMatch(list, />Change</);
});

test('names have no role tips and the project code has a title on hover, no ⓘ', () => {
  for (const page of [program, leader]) {
    assert.doesNotMatch(page, /<Tip\b/);
    assert.doesNotMatch(page, /ⓘ/);
  }
  assert.doesNotMatch(list, /<Tip\b|ⓘ/);
  assert.match(list, /title=\{row\.project\.title\}/);
  const css = source('styles/global.css');
  assert.match(css, /\.au-table thead th\{position:sticky;top:0/);
  assert.match(css, /\.program-shell \.au-table td\{[^}]*white-space:nowrap/);
  assert.match(css, /@media \(max-width: 720px\)\{\s*\.au-scroll/);
});

test('both pages use the compact list and keep the hiding rules for repeated lines', () => {
  assert.match(program, /<AuditLogList rows=\{visibleEntries\(entries\)\.map/);
  assert.match(program, /visibleChanges\(entry\)/);
  assert.match(leader, /<AuditLogList rows=\{filtered\.map\(auditRow\)\} \/>/);
});

// ------------------------------------------------------------------ search

test('the search box comes first and is sent to the server as q after ~300 ms', () => {
  assert.equal(SEARCH_DEBOUNCE_MS, 300);
  assert.equal(searchParam('  amina '), 'amina');
  assert.equal(searchParam('   '), undefined);

  // Program Leader: q is the first filter, read from the URL and sent with paging.
  assert.match(program, /const FILTERS = \['q', 'action', 'project', 'from', 'to'\]/);
  assert.match(program, /placeholder="Search user, record or action"/);
  assert.ok(program.indexOf('id="audit-search"') < program.indexOf('id="audit-action"'));
  assert.match(program, /next\.set\('q', value\);[\s\S]*?\}, SEARCH_DEBOUNCE_MS\);/);
  assert.match(program, /fetchProgramAudit\(\{ \.\.\.params, page: 1 \}/);
  assert.match(program, /fetchProgramAudit\(\{ \.\.\.filters, user: legacyUser, page:/);

  // Project Leader / Finance: q on /api/audit, no search on the client.
  assert.match(leader, /placeholder="Search user, record or action"/);
  assert.match(leader, /new URLSearchParams\(\{ q: query \}\)/);
  assert.match(leader, /setTimeout\(\(\) => setQ\(search\), SEARCH_DEBOUNCE_MS\)/);
  assert.doesNotMatch(leader, /toLowerCase\(\)\.includes\(search/);
});

test('there is no User dropdown; links to a user put the name in the search box', () => {
  for (const page of [program, leader]) {
    assert.doesNotMatch(page, /audit-user|All users|<label[^>]*>User<\/label>/);
  }
  // "View in audit log" from Users and roles opens the search for that name.
  const manage = source('components/program/ProgramUserManage.jsx');
  assert.match(manage, /to=\{`\/program-audit\?q=\$\{encodeURIComponent\(user\.name\)\}`\}/);
  assert.doesNotMatch(manage, /program-audit\?user=/);
  // An old ?user=<id> URL still filters until the name is known, then becomes q=<name>.
  assert.match(program, /const legacyUser = searchParams\.get\('user'\) \?\? ''/);
  assert.match(program, /next\.delete\('user'\);\s*next\.set\('q', name\);/);
  assert.match(program, /\[\.\.\.FILTERS, 'user'\]\.forEach\(\(key\) => next\.delete\(key\)\)/);
});

test('an empty result says "No entries match" with Clear filters; paging text stays', () => {
  for (const page of [program, leader]) {
    assert.match(page, /'No entries match'/);
    assert.match(page, /onClick=\{clearFilters\}>Clear filters</);
  }
  assert.match(program, /Showing \{entries\.length\} of \{total\} entries/);
  assert.match(program, /'Load more'/);
  assert.match(leader, /entries displayed|entr\{log\.length === 1 \? 'y' : 'ies'\} displayed/);
});
