import { formatMoneyText } from '../lib/moneyInput';

/**
 * The peso input used by every money field. It keeps what is typed
 * (commas, spaces and a leading ₱ are fine; lib/moneyInput parses them) and
 * shows the value with commas when the field loses focus ("3,178,376.00").
 * `onChange` receives a change event, or `{ target: { value } }` on blur.
 */
export default function MoneyInput({ value, onChange, onBlur, className = 'program-input', ...rest }) {
  function blur(event) {
    const formatted = formatMoneyText(value);
    if (formatted !== String(value ?? '')) onChange?.({ target: { value: formatted } });
    onBlur?.(event);
  }

  return (
    <input
      {...rest}
      type="text"
      inputMode="decimal"
      autoComplete="off"
      className={className}
      value={value ?? ''}
      onChange={onChange}
      onBlur={blur}
    />
  );
}
