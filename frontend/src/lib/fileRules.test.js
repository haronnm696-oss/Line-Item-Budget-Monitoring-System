import assert from 'node:assert/strict';
import test from 'node:test';

import { FILE_HINT, MAX_FILE_BYTES, fileError } from './fileRules.js';

test('the limit is 5 MB, PDF / JPG / PNG only', () => {
  assert.equal(FILE_HINT, 'PDF, JPG or PNG, up to 5 MB');
  assert.equal(fileError({ name: 'receipt.pdf', size: MAX_FILE_BYTES }), null);
  assert.equal(fileError({ name: 'photo.JPG', size: 1000 }), null);
  assert.equal(fileError({ name: 'scan.pdf', size: MAX_FILE_BYTES + 1 }), '“scan.pdf” is 5.0 MB. The limit is 5 MB per file.');
  assert.equal(fileError({ name: 'big.png', size: 7.4 * 1024 * 1024 }), '“big.png” is 7.4 MB. The limit is 5 MB per file.');
  assert.equal(fileError({ name: 'notes.txt', size: 10 }), 'Upload a PDF, JPG or PNG file.');
  assert.equal(fileError(null), null);
});
