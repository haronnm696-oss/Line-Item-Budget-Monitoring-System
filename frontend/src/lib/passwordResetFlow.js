// Password reset by emailed 6-digit code (pages/ForgotPassword.jsx).
// Pure helpers so the three steps can be tested with node:test.
//
//   email    -> POST /api/forgot-password          (same reply for any address)
//   code     -> POST /api/forgot-password/verify   (returns a reset token)
//   password -> POST /api/reset-password           (email + token + new password)

export const CODE_LENGTH = 6;
export const CODE_TTL_SECONDS = 10 * 60;
export const RESEND_COOLDOWN_SECONDS = 60;
export const RESET_TOKEN_TTL_SECONDS = 10 * 60;

export const STEPS = Object.freeze({ EMAIL: 'email', CODE: 'code', PASSWORD: 'password' });

export const INVALID_CODE_MESSAGE = 'Invalid or expired code';
export const CODE_EXPIRED_MESSAGE = 'This code has expired. Request a new code.';
export const SESSION_EXPIRED_MESSAGE = 'Your reset session has expired. Request a new code.';
export const RESET_DONE_NOTICE = 'Your password has been reset. Sign in with your new password.';

export function emptyCode() {
  return Array(CODE_LENGTH).fill('');
}

/** Digits only, at most 6 ("123 456", "12-34-56" -> "123456"). */
export function codeDigits(value) {
  return String(value ?? '').replace(/\D/g, '').slice(0, CODE_LENGTH);
}

export function codeValue(digits) {
  return digits.join('');
}

export function isCodeComplete(digits) {
  return /^\d{6}$/.test(codeValue(digits));
}

/**
 * Typing or pasting `input` into box `index`. A single digit fills that box
 * and moves on; a paste fills boxes from `index` onward (a full 6-digit paste
 * always fills from the first box). Non-digits are ignored.
 * Returns the new digits and the box to focus.
 */
export function enterCode(digits, index, input) {
  const typed = codeDigits(input);
  if (!typed) return { digits: [...digits], focus: index };

  const start = typed.length === CODE_LENGTH ? 0 : index;
  const next = [...digits];
  let position = start;
  for (const digit of typed) {
    if (position >= CODE_LENGTH) break;
    next[position] = digit;
    position += 1;
  }
  return { digits: next, focus: Math.min(position, CODE_LENGTH - 1) };
}

/** Backspace in box `index`: clear it, or when it is empty clear and focus the one before. */
export function eraseCode(digits, index) {
  const next = [...digits];
  if (next[index]) {
    next[index] = '';
    return { digits: next, focus: index };
  }
  const previous = Math.max(0, index - 1);
  next[previous] = '';
  return { digits: next, focus: previous };
}

/** Whole seconds left until `deadline` (ms timestamp), never negative. */
export function secondsUntil(deadline, now = Date.now()) {
  if (!deadline) return 0;
  return Math.max(0, Math.ceil((deadline - now) / 1000));
}

export function formatCountdown(seconds) {
  const safe = Math.max(0, Math.floor(seconds));
  const minutes = Math.floor(safe / 60);
  return `${minutes}:${String(safe % 60).padStart(2, '0')}`;
}

/** Deadlines (ms) after a code was sent at `now`. */
export function codeSentDeadlines(now = Date.now(), response = {}) {
  const ttl = Number(response?.code_expires_in) > 0 ? Number(response.code_expires_in) : CODE_TTL_SECONDS;
  const resend = Number(response?.resend_in) > 0 ? Number(response.resend_in) : RESEND_COOLDOWN_SECONDS;
  return { codeExpiresAt: now + ttl * 1000, resendAt: now + resend * 1000 };
}

export function resendLabel(secondsLeft, sending = false) {
  if (sending) return 'Sending…';
  return secondsLeft > 0 ? `Resend code in ${formatCountdown(secondsLeft)}` : 'Resend code';
}

function firstError(value) {
  return Array.isArray(value) ? value[0] : value;
}

/**
 * Where a failed reset (step 3) leaves the user: an invalid or expired reset
 * token goes back to step 1; a password problem stays on step 3.
 */
export function resetFailure(err) {
  const errors = err?.errors || {};
  if (errors.reset_token || errors.email) {
    return { step: STEPS.EMAIL, error: SESSION_EXPIRED_MESSAGE };
  }
  if (errors.password) {
    const message = String(firstError(errors.password) || '');
    return /confirmation/i.test(message)
      ? { step: STEPS.PASSWORD, confirmError: 'Passwords do not match.' }
      : { step: STEPS.PASSWORD, passwordError: message || 'Your password must meet all four rules below.' };
  }
  return { step: STEPS.PASSWORD, error: err?.message || 'We could not reset your password. Please try again.' };
}

/** The message shown under the code boxes after a failed verify (step 2). */
export function verifyFailure(err) {
  if (err?.status === 429) return err.message || 'Too many attempts. Please wait a minute and try again.';
  if (err?.errors?.code) return INVALID_CODE_MESSAGE;
  return err?.message || 'We could not check the code. Check your connection and try again.';
}
