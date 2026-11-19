/**
 * The sentence after "Submitted …" in a submit toast, taken from the
 * server's message so it names the real next reviewer: the Project Leader,
 * Finance/Admin (an item the Project Leader made skips their approval) or
 * the Program Leader (a payroll that pays the Project Leader).
 */
export function nextStep(message) {
  const text = typeof message === 'string' ? message : '';
  const after = text.split('submitted. ')[1];
  if (after) return after;
  if (/to the Project Leader\.?$/.test(text)) return 'It is waiting for the Project Leader.';
  return 'It is on its way to the next reviewer.';
}
