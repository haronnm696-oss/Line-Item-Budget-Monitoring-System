import assert from 'node:assert/strict';
import test from 'node:test';

import { ApiError, LOAD_ERROR, SAVE_ERROR, performRead, performWrite } from './apiResult.js';

const httpError = (status) => Object.assign(new Error(`Request failed with status code ${status}`), { response: { status, data: {} } });

test('a failed write shows "Could not save" and stores nothing', async () => {
  for (const failure of [httpError(404), httpError(422), httpError(403), httpError(500), new Error('Network Error')]) {
    const store = [];
    const ui = { message: '', rows: store };

    // A page saves, then adds the server's record to its list only on success.
    try {
      const saved = await performWrite(async () => { throw failure; });
      ui.rows = [...ui.rows, saved];
    } catch (error) {
      assert.ok(error instanceof ApiError);
      ui.message = error.message;
    }

    assert.equal(ui.message, SAVE_ERROR);
    assert.deepEqual(ui.rows, [], 'nothing was added');
    assert.equal(store.length, 0);
  }
});

test('a write never returns sample data, even for a 404', async () => {
  await assert.rejects(() => performWrite(async () => { throw httpError(404); }), { message: SAVE_ERROR, status: 404 });
});

test('a non-2xx response is not success', async () => {
  await assert.rejects(() => performWrite(async () => ({ status: 302, data: { id: 1 } })), { message: SAVE_ERROR });
  await assert.rejects(() => performRead(async () => ({ status: 404, data: [] })), { message: LOAD_ERROR });
});

test('field errors from a 422 are kept for the form', async () => {
  const failure = Object.assign(new Error('Unprocessable'), { response: { status: 422, data: { errors: { amount: ['Too large.'] } } } });
  await assert.rejects(() => performWrite(async () => { throw failure; }), (error) => error.fieldErrors.amount[0] === 'Too large.');
});

test('a successful request returns the server data', async () => {
  assert.deepEqual(await performWrite(async () => ({ status: 201, data: { id: 7 } })), { id: 7 });
  assert.deepEqual(await performRead(async () => ({ status: 200, data: [1, 2] })), [1, 2]);
});

test('a failed read is an error, never sample data', async () => {
  await assert.rejects(() => performRead(async () => { throw httpError(404); }), { message: LOAD_ERROR, status: 404 });
});
