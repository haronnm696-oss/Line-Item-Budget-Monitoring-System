import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import {
  AVATAR_SIZE_ERROR,
  AVATAR_TYPE_ERROR,
  avatarFileError,
  avatarView,
  centeredOffset,
  clampOffset,
  coverScale,
  cropRect,
  prepareAvatarFile,
  zoomOffset,
} from './avatar.js';

// No JSX renderer is set up for node:test, so component checks read the sources.
const source = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');

test('the avatar shows the photo when avatar_url exists', () => {
  const view = avatarView('/api/users/7/avatar?v=abc', 'NH', (path) => `https://api.test${path}`);
  assert.equal(view.kind, 'image');
  assert.equal(view.src, 'https://api.test/api/users/7/avatar?v=abc');
  assert.equal(view.initials, 'NH');
});

test('the avatar shows the initials when there is no photo', () => {
  for (const url of [null, undefined, '', '   ']) {
    const view = avatarView(url, 'NH');
    assert.equal(view.kind, 'initials', String(url));
    assert.equal(view.src, null);
    assert.equal(view.initials, 'NH');
  }
  assert.equal(avatarView(null, '').initials, 'U');
});

test('UserAvatar renders an <img> for a photo, the initials otherwise, and falls back on a broken photo', () => {
  const component = source('../components/UserAvatar.jsx');
  assert.match(component, /avatarView\(avatarUrl, initials, apiUrl\)/);
  assert.match(component, /showImage\s*\?\s*<img src=\{view\.src\} alt="" onError=/);
  assert.match(component, /:\s*view\.initials\}/);
  assert.match(component, /aria-hidden="true"/);
});

test('the header user chip and its menu use UserAvatar with the user\'s photo', () => {
  const shell = source('../layouts/RoleShell.jsx');
  assert.equal((shell.match(/<UserAvatar avatarUrl=\{user\?\.avatar_url\} initials=\{initials\} \/>/g) || []).length, 2);
  assert.doesNotMatch(shell, /<span className="program-avatar"/);
  assert.match(shell, /<Link to="\/profile"/);
});

test('only JPG, PNG and WebP up to 2 MB may be picked', () => {
  const mb = 1024 * 1024;
  assert.equal(avatarFileError({ name: 'me.jpg', type: 'image/jpeg', size: mb }), '');
  assert.equal(avatarFileError({ name: 'me.png', type: 'image/png', size: 2 * mb }), '');
  assert.equal(avatarFileError({ name: 'me.webp', type: '', size: 10 }), '');
  assert.equal(avatarFileError({ name: 'me.png', type: 'image/png', size: 2 * mb + 1 }), AVATAR_SIZE_ERROR);
  assert.equal(avatarFileError({ name: 'scan.pdf', type: 'application/pdf', size: 10 }), AVATAR_TYPE_ERROR);
  assert.equal(avatarFileError({ name: 'anim.gif', type: 'image/gif', size: 10 }), AVATAR_TYPE_ERROR);
  assert.equal(avatarFileError(null), AVATAR_TYPE_ERROR);
});

test('the photo picker refuses wrong types and large files with a clear message', async () => {
  assert.deepEqual(await prepareAvatarFile({ name: 'scan.pdf', type: 'application/pdf', size: 10 }), { error: AVATAR_TYPE_ERROR });
  assert.deepEqual(await prepareAvatarFile({ name: 'big.jpg', type: 'image/jpeg', size: 3 * 1024 * 1024 }), { error: AVATAR_SIZE_ERROR });
  const ok = { name: 'me.webp', type: 'image/webp', size: 1000 };
  assert.equal((await prepareAvatarFile(ok)).file, ok);
});

test('the crop square always stays covered by the image', () => {
  // 800x500 image in a 240 frame: zoom 1 fits the short side.
  const scale = coverScale(800, 500, 240);
  assert.equal(scale, 240 / 500);
  const centered = centeredOffset(800, 500, scale, 240);
  assert.deepEqual(centered, { x: (240 - 800 * scale) / 2, y: 0 });

  assert.deepEqual(clampOffset({ x: 50, y: 50 }, 800, 500, scale, 240), { x: 0, y: 0 });
  assert.deepEqual(clampOffset({ x: -9999, y: -9999 }, 800, 500, scale, 240), { x: 240 - 800 * scale, y: 0 });
});

test('zooming keeps the center and the crop maps back to image pixels', () => {
  const base = coverScale(400, 400, 200); // 0.5
  const offset = zoomOffset({ x: 0, y: 0 }, base, base * 2, 400, 400, 200);
  assert.deepEqual(offset, { x: -100, y: -100 });
  assert.deepEqual(cropRect(offset, base * 2, 200), { sx: 100, sy: 100, size: 200 });
  assert.deepEqual(cropRect({ x: 0, y: 0 }, base, 200), { sx: -0, sy: -0, size: 400 });
});

test('the profile page uploads, removes and shows clear errors', () => {
  const page = source('../pages/Profile.jsx');
  assert.match(page, /label="Upload photo"/);
  assert.match(page, /<FileField[\s\S]*accept=\{AVATAR_ACCEPT\}[\s\S]*prepare=\{prepareAvatarFile\}/);
  assert.match(page, /Remove photo/);
  assert.match(page, /role="alert"/);
  assert.match(page, /updateUser\(data\?\.user\)/);
  const api = source('../api/profile.js');
  assert.match(api, /form\.append\('avatar', blob, 'avatar\.jpg'\)/);
  assert.match(api, /client\.delete\(ENDPOINTS\.profileAvatar\)/);
});
