import { useRef } from 'react';
import { CODE_LENGTH, enterCode, eraseCode } from '../../lib/passwordResetFlow';

/**
 * Six one-digit boxes for the emailed reset code. Typing moves to the next
 * box, Backspace goes back, and pasting a code (with or without spaces) fills
 * every box. `onComplete` runs when the sixth digit is entered.
 */
export default function CodeInput({ id, digits, onChange, onComplete, disabled = false, invalid = false, describedBy }) {
  const boxes = useRef([]);

  function focusBox(index) {
    const box = boxes.current[index];
    if (box) {
      box.focus();
      box.select();
    }
  }

  function apply({ digits: next, focus }) {
    onChange(next);
    focusBox(focus);
    if (next.every((digit) => digit !== '') && onComplete) onComplete(next);
  }

  function handleKeyDown(event, index) {
    if (event.key === 'Backspace') {
      event.preventDefault();
      const { digits: next, focus } = eraseCode(digits, index);
      onChange(next);
      focusBox(focus);
    } else if (event.key === 'ArrowLeft' && index > 0) {
      event.preventDefault();
      focusBox(index - 1);
    } else if (event.key === 'ArrowRight' && index < CODE_LENGTH - 1) {
      event.preventDefault();
      focusBox(index + 1);
    }
  }

  return (
    <div className="signin-code" role="group" aria-labelledby={`${id}-label`} aria-describedby={describedBy}>
      {digits.map((digit, index) => (
        <input
          // The boxes never reorder, so the position is a stable key.
          key={index}
          id={index === 0 ? id : `${id}-${index}`}
          ref={(element) => { boxes.current[index] = element; }}
          className={`signin-code__box${invalid ? ' is-invalid' : ''}`}
          type="text"
          inputMode="numeric"
          pattern="[0-9]*"
          autoComplete={index === 0 ? 'one-time-code' : 'off'}
          maxLength={CODE_LENGTH}
          value={digit}
          disabled={disabled}
          aria-label={`Digit ${index + 1} of ${CODE_LENGTH}`}
          aria-invalid={invalid}
          onFocus={(event) => event.target.select()}
          onKeyDown={(event) => handleKeyDown(event, index)}
          onPaste={(event) => {
            event.preventDefault();
            apply(enterCode(digits, index, event.clipboardData.getData('text')));
          }}
          onChange={(event) => {
            const value = event.target.value;
            if (value === '') {
              const next = [...digits];
              next[index] = '';
              onChange(next);
              return;
            }
            // The box may briefly hold the old digit plus the new one (or a
            // whole code from autofill); enterCode keeps only the digits.
            const typed = digit && value.length === 2 ? value.replace(digit, '') : value;
            apply(enterCode(digits, index, typed));
          }}
        />
      ))}
    </div>
  );
}
