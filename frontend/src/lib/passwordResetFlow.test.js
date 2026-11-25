import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

import {
  CODE_LENGTH,
  INVALID_CODE_MESSAGE,
  SESSION_EXPIRED_MESSAGE,
  STEPS,
  codeDigits,
  codeSentDeadlines,
  codeValue,
  emptyCode,
  enterCode,
  eraseCode,
  formatCountdown,
  isCodeComplete,
  resendLabel,
  resetFailure,
  secondsUntil,
  verifyFailure,
} from './passwordResetFlow.js';

// No JSX renderer is set up for node:test, so the page wiring is checked on the source.
const SRC = fileURLToPath(new URL('..', import.meta.url));
const source = (path) => readFileSync(join(SRC, path), 'utf8');

// Step 1: request a code

test('step 1: a sent code starts a 10-minute expiry and a 60-second resend cooldown', () => {
  const now = 1_000_000;
  const { codeExpiresAt, resendAt } = codeSentDeadlines(now);
  assert.equal(secondsUntil(codeExpiresAt, now), 600);
  assert.equal(secondsUntil(resendAt, now), 60);
  assert.equal(formatCountdown(secondsUntil(codeExpiresAt, now)), '10:00');

  // The server's numbers win when given.
  const custom = codeSentDeadlines(now, { code_expires_in: 300, resend_in: 30 });
  assert.equal(secondsUntil(custom.codeExpiresAt, now), 300);
  assert.equal(secondsUntil(custom.resendAt, now), 30);
});

test('step 1: the page posts the email and moves to the code step', () => {
  const page = source('pages/ForgotPassword.jsx');
  assert.match(page, /await requestPasswordReset\(cleanEmail\)/);
  assert.match(page, /setStep\(STEPS\.CODE\)/);
  assert.match(page, /Send code/);
  assert.doesNotMatch(page, /reset link|Resend link/i);
  const api = source('api/endpoints.js');
  assert.match(api, /forgotPassword: '\/api\/forgot-password'/);
  assert.match(api, /verifyResetCode: '\/api\/forgot-password\/verify'/);
});

// Step 2: enter the code

test('step 2: codes keep digits only, at most six', () => {
  assert.equal(codeDigits('123 456'), '123456');
  assert.equal(codeDigits('12-34-56-78'), '123456');
  assert.equal(codeDigits('abc'), '');
  assert.equal(codeDigits(null), '');
  assert.deepEqual(emptyCode(), ['', '', '', '', '', '']);
  assert.equal(CODE_LENGTH, 6);
});

test('step 2: typing a digit fills the box and advances', () => {
  let state = { digits: emptyCode(), focus: 0 };
  for (const digit of '4815') {
    state = enterCode(state.digits, state.focus, digit);
  }
  assert.deepEqual(state.digits, ['4', '8', '1', '5', '', '']);
  assert.equal(state.focus, 4);

  // A letter changes nothing.
  const same = enterCode(state.digits, 4, 'x');
  assert.deepEqual(same.digits, state.digits);
  assert.equal(same.focus, 4);

  // The last box keeps focus.
  const full = enterCode(['1', '2', '3', '4', '5', ''], 5, '6');
  assert.equal(codeValue(full.digits), '123456');
  assert.equal(full.focus, 5);
  assert.equal(isCodeComplete(full.digits), true);
});

test('step 2: pasting a whole code fills every box from the first', () => {
  const pasted = enterCode(['9', '', '', '', '', ''], 3, ' 123 456 ');
  assert.equal(codeValue(pasted.digits), '123456');
  assert.equal(isCodeComplete(pasted.digits), true);

  // A partial paste fills from the box it lands in.
  const partial = enterCode(emptyCode(), 2, '78');
  assert.deepEqual(partial.digits, ['', '', '7', '8', '', '']);
  assert.equal(partial.focus, 4);
  assert.equal(isCodeComplete(partial.digits), false);
});

test('step 2: backspace clears the box, then moves back', () => {
  const digits = ['1', '2', '3', '', '', ''];
  const first = eraseCode(digits, 2);
  assert.deepEqual(first.digits, ['1', '2', '', '', '', '']);
  assert.equal(first.focus, 2);
  const second = eraseCode(first.digits, 2);
  assert.deepEqual(second.digits, ['1', '', '', '', '', '']);
  assert.equal(second.focus, 1);
  assert.equal(eraseCode(emptyCode(), 0).focus, 0);
});

test('step 2: countdown and resend label', () => {
  assert.equal(formatCountdown(599), '9:59');
  assert.equal(formatCountdown(5), '0:05');
  assert.equal(formatCountdown(-3), '0:00');
  assert.equal(secondsUntil(0), 0);
  assert.equal(secondsUntil(10_500, 10_000), 1);
  assert.equal(secondsUntil(9_000, 10_000), 0);
  assert.equal(resendLabel(42), 'Resend code in 0:42');
  assert.equal(resendLabel(0), 'Resend code');
  assert.equal(resendLabel(0, true), 'Sending…');
});

test('step 2: a wrong or expired code shows one generic message', () => {
  assert.equal(verifyFailure({ status: 422, errors: { code: ['anything'] } }), INVALID_CODE_MESSAGE);
  assert.equal(INVALID_CODE_MESSAGE, 'Invalid or expired code');
  assert.match(verifyFailure({ status: 429, message: 'Too many attempts.' }), /Too many/);
  assert.match(verifyFailure({}), /connection/);
});

test('step 2: the page shows the email, the countdown, resend and the code boxes', () => {
  const page = source('pages/ForgotPassword.jsx');
  assert.match(page, /Code sent to <strong>\{submittedEmail\}<\/strong>/);
  assert.match(page, /Code expires in \$\{formatCountdown\(codeSecondsLeft\)\}/);
  assert.match(page, /disabled=\{resendSecondsLeft > 0 \|\| resending\}/);
  assert.match(page, /verifyResetCode\(\{ email: submittedEmail, code \}\)/);
  assert.match(page, /onComplete=\{\(next\) => verify\(codeValue\(next\)\)\}/);

  const input = source('components/auth/CodeInput.jsx');
  assert.match(input, /inputMode="numeric"/);
  assert.match(input, /autoComplete=\{index === 0 \? 'one-time-code' : 'off'\}/);
  assert.match(input, /onPaste=/);
  assert.match(input, /clipboardData\.getData\('text'\)/);
});

// Step 3: new password

test('step 3: an invalid reset token sends the user back to request a new code', () => {
  assert.deepEqual(resetFailure({ errors: { reset_token: ['x'] } }), { step: STEPS.EMAIL, error: SESSION_EXPIRED_MESSAGE });
  assert.equal(resetFailure({ errors: { email: ['x'] } }).step, STEPS.EMAIL);
});

test('step 3: password errors stay on the password step', () => {
  assert.deepEqual(
    resetFailure({ errors: { password: ['The password confirmation does not match.'] } }),
    { step: STEPS.PASSWORD, confirmError: 'Passwords do not match.' },
  );
  assert.deepEqual(
    resetFailure({ errors: { password: ['Use at least one symbol.'] } }),
    { step: STEPS.PASSWORD, passwordError: 'Use at least one symbol.' },
  );
  assert.equal(resetFailure({ message: 'Offline' }).error, 'Offline');
});

test('step 3: the page posts the reset token and returns to sign in with a notice', () => {
  const page = source('pages/ForgotPassword.jsx');
  assert.match(page, /passwordRules\(password, '', '', submittedEmail\)/);
  assert.match(page, /resetToken,\s*password,\s*passwordConfirmation: confirmPassword/);
  assert.match(page, /navigate\('\/login', \{ replace: true, state: \{ notice: RESET_DONE_NOTICE \} \}\)/);
  assert.match(source('pages/Login.jsx'), /location\.state\?\.notice/);
  assert.match(source('api/auth.js'), /reset_token: resetToken/);
});

test('the link-based reset page is gone and /reset-password redirects to /forgot-password', () => {
  assert.equal(existsSync(join(SRC, 'pages/ResetPassword.jsx')), false);
  const app = source('App.jsx');
  assert.match(app, /<Route path="\/reset-password" element=\{<Navigate to="\/forgot-password" replace \/>\} \/>/);
  assert.doesNotMatch(app, /import ResetPassword/);
});
