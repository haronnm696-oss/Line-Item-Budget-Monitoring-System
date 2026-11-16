import assert from 'node:assert/strict';
import test from 'node:test';

import { MAX_FILE_BYTES } from './fileRules.js';
import { fitWithin, formatBytes, jpegName, keepPng, kindOf, pickResult } from './imagePrep.js';

test('PDFs, JPGs and PNGs are told apart by name or type', () => {
  assert.equal(kindOf({ name: 'or.PDF' }), 'pdf');
  assert.equal(kindOf({ name: 'photo.jpeg' }), 'jpeg');
  assert.equal(kindOf({ name: 'scan', type: 'image/png' }), 'png');
  assert.equal(kindOf({ name: 'notes.txt' }), 'other');
});

test('the longest side becomes at most 1600 px, keeping the aspect ratio', () => {
  assert.deepEqual(fitWithin(4032, 3024), { width: 1600, height: 1200, resized: true });
  assert.deepEqual(fitWithin(3000, 6000), { width: 800, height: 1600, resized: true });
  assert.deepEqual(fitWithin(1600, 900), { width: 1600, height: 900, resized: false });
  assert.deepEqual(fitWithin(800, 600), { width: 800, height: 600, resized: false });
});

test('a PNG is kept only when small and transparent', () => {
  assert.equal(keepPng({ size: 200 * 1024 }, true), true);
  assert.equal(keepPng({ size: 200 * 1024 }, false), false);
  assert.equal(keepPng({ size: 3 * 1024 * 1024 }, true), false);
});

test('the compressed JPEG is used unless the original was already smaller and fits', () => {
  assert.equal(pickResult({ size: 900 * 1024 }, 300 * 1024, true), 'compressed');
  assert.equal(pickResult({ size: 120 * 1024 }, 180 * 1024, false), 'original');
  assert.equal(pickResult({ size: MAX_FILE_BYTES + 1 }, MAX_FILE_BYTES + 5, false), 'compressed');
});

test('names and sizes for the "final size" line', () => {
  assert.equal(jpegName('Receipt scan.PNG'), 'Receipt scan.jpg');
  assert.equal(jpegName('noext'), 'noext.jpg');
  assert.equal(formatBytes(2.4 * 1024 * 1024), '2.4 MB');
  assert.equal(formatBytes(310 * 1024), '310 KB');
});
