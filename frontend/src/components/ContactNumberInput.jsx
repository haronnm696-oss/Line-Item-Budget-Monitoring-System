import { CONTACT_NUMBER_LENGTH, pasteContactNumber, sanitizeContactNumber } from '../lib/authValidation';

/**
 * Contact number input: digits only (spaces, dashes and letters are dropped
 * as the user types or pastes), at most 11, numeric keypad on phones.
 * `onValueChange` receives the cleaned value. The counter ("7/11") and the
 * error message are rendered by the form around it.
 */
export default function ContactNumberInput({ value, onValueChange, ...props }) {
  const current = String(value ?? '');
  return (
    <input
      type="tel"
      inputMode="numeric"
      autoComplete="tel"
      placeholder="09171234567"
      {...props}
      maxLength={CONTACT_NUMBER_LENGTH}
      value={current}
      onChange={(event) => onValueChange(sanitizeContactNumber(event.target.value))}
      onPaste={(event) => {
        // Clean the pasted text before the browser's maxLength cuts it.
        event.preventDefault();
        const field = event.currentTarget;
        onValueChange(pasteContactNumber(current, event.clipboardData?.getData('text'), field.selectionStart, field.selectionEnd));
      }}
    />
  );
}
