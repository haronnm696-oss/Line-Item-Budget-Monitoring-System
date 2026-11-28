import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import {
  AVATAR_SIZE_ERROR,
  AVATAR_TYPE_ERROR,
  avatarFileError,
  avatarView,
  anchoredOffset,
  centeredOffset,
  clampOffset,
  clampZoom,
  coverScale,
  cropRect,
  framePoint,
  prepareAvatarFile,
  wheelZoomFactor,
  zoomAt,
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

// Image px under a frame point, for a given offset and scale.
const imagePointAt = (point, offset, scale) => ({ x: (point.x - offset.x) / scale, y: (point.y - offset.y) / scale });
const close = (actual, expected) => {
  assert.ok(Math.abs(actual.x - expected.x) < 1e-9 && Math.abs(actual.y - expected.y) < 1e-9,
    `${JSON.stringify(actual)} != ${JSON.stringify(expected)}`);
};

test('zoom anchored at the cursor keeps the point under the cursor in place', () => {
  const base = coverScale(1200, 900, 240);
  const start = { x: -150, y: -60 };
  const oldScale = base * 1.5;
  for (const point of [{ x: 30, y: 200 }, { x: 120, y: 120 }, { x: 239, y: 0 }]) {
    const before = imagePointAt(point, start, oldScale);
    for (const newScale of [base * 2.4, base * 1.6]) {
      const moved = anchoredOffset(start, oldScale, newScale, point);
      close(imagePointAt(point, moved, newScale), before);
    }
  }
});

test('cursor-anchored zoom stays exact while the square stays covered, and is clamped otherwise', () => {
  const width = 400;
  const height = 400;
  const frame = 200;
  const base = coverScale(width, height, frame); // 0.5
  // Zoom in 1 -> 2 at (50, 150): fully inside the allowed range, so exact.
  const zoomedIn = zoomAt({ x: 0, y: 0 }, base, base * 2, { x: 50, y: 150 }, width, height, frame);
  assert.deepEqual(zoomedIn, { x: -50, y: -150 });
  close(imagePointAt({ x: 50, y: 150 }, zoomedIn, base * 2), { x: 100, y: 300 });

  // Zoom out 2 -> 1 at a corner: the result is clamped so no edge shows.
  const zoomedOut = zoomAt({ x: -200, y: -200 }, base * 2, base, { x: 10, y: 10 }, width, height, frame);
  assert.deepEqual(zoomedOut, { x: 0, y: 0 });

  // Any anchor and any scale keep the frame covered.
  for (const point of [{ x: 0, y: 0 }, { x: 200, y: 200 }, { x: 37, y: 181 }]) {
    for (const newScale of [base, base * 1.3, base * 3]) {
      const offset = zoomAt({ x: -120, y: -40 }, base * 2, newScale, point, width, height, frame);
      assert.ok(offset.x <= 0 && offset.y <= 0, 'no gap at the top/left');
      assert.ok(offset.x + width * newScale >= frame - 1e-9, 'no gap at the right');
      assert.ok(offset.y + height * newScale >= frame - 1e-9, 'no gap at the bottom');
    }
  }
});

test('the center-anchored zoom (slider, +/-) is zoomAt at the frame center', () => {
  const base = coverScale(640, 480, 240);
  const start = { x: -40, y: -10 };
  assert.deepEqual(
    zoomOffset(start, base * 1.2, base * 2, 640, 480, 240),
    zoomAt(start, base * 1.2, base * 2, { x: 120, y: 120 }, 640, 480, 240),
  );
});

test('zoom keeps the same limits: never below covering the square, never above the max', () => {
  assert.equal(clampZoom(0.4, 3), 1);
  assert.equal(clampZoom(1, 3), 1);
  assert.equal(clampZoom(2.25, 3), 2.25);
  assert.equal(clampZoom(7, 3), 3);
  assert.equal(clampZoom(Number.NaN, 3), 1);
});

test('wheel up zooms in, wheel down zooms out, and pinch zooms faster per pixel', () => {
  assert.ok(wheelZoomFactor(-100) > 1);
  assert.ok(wheelZoomFactor(100) < 1);
  assert.equal(wheelZoomFactor(0), 1);
  close({ x: wheelZoomFactor(100) * wheelZoomFactor(-100), y: 0 }, { x: 1, y: 0 });
  assert.ok(wheelZoomFactor(-10, 0, true) > wheelZoomFactor(-10, 0, false));
  assert.equal(wheelZoomFactor(-3, 1), wheelZoomFactor(-48, 0)); // 3 lines = 48 px
  // One huge event never jumps more than one capped step.
  assert.equal(wheelZoomFactor(-5000), wheelZoomFactor(-120));
});

test('screen points map to frame px even when the frame is drawn smaller', () => {
  assert.deepEqual(framePoint(160, 90, { left: 100, top: 50, width: 240, height: 240 }, 240), { x: 60, y: 40 });
  assert.deepEqual(framePoint(160, 110, { left: 100, top: 50, width: 120, height: 120 }, 240), { x: 120, y: 120 });
});

test('the cropper zooms with the wheel, pinch and +/- keys (no slider), and shows the hint', () => {
  const page = source('../pages/Profile.jsx');
  assert.match(page, /addEventListener\('wheel', onWheel, \{ passive: false \}\)/);
  assert.match(page, /event\.preventDefault\(\);\s*const point = framePoint/);
  assert.doesNotMatch(page, /type="range"/);
  assert.doesNotMatch(page, /profile-zoom/);
  assert.doesNotMatch(source('../styles/global.css'), /\.profile-zoom/);
  // The crop area takes focus so keyboard users can zoom and move.
  assert.match(page, /className="profile-crop-frame"[\s\S]*?tabIndex=\{0\}[\s\S]*?aria-label="[^"]*\+ and − keys to zoom[^"]*"[\s\S]*?onKeyDown=\{onKeyDown\}/);
  assert.match(page, /event\.key === '\+' \|\| event\.key === '='/);
  assert.match(page, /event\.key === '-' \|\| event\.key === '_'/);
  assert.match(page, /Drag to move · scroll or pinch to zoom/);
  assert.match(page, /const OUTPUT = 512;/);
});

test('the profile page uploads, removes and shows clear errors', () => {
  const page = source('../pages/Profile.jsx');
  assert.match(page, /label="Change photo"/);
  assert.match(page, /<FileField[\s\S]*accept=\{AVATAR_ACCEPT\}[\s\S]*prepare=\{prepareAvatarFile\}/);
  assert.match(page, /'Remove'/);
  assert.match(page, /role="alert"/);
  assert.match(page, /updateUser\(data\?\.user\)/);
  const api = source('../api/profile.js');
  assert.match(api, /form\.append\('avatar', blob, 'avatar\.jpg'\)/);
  assert.match(api, /client\.delete\(ENDPOINTS\.profileAvatar\)/);
});
