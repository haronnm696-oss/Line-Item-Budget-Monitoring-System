import { useEffect, useRef, useState } from 'react';

const prefersReducedMotion = () =>
  typeof window !== 'undefined' &&
  window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

/**
 * Counts from the previous value to the new one. Mount starts at zero so
 * dashboard figures roll up instead of popping in.
 */
export default function AnimatedNumber({ value, format, duration = 900 }) {
  const target = Number(value) || 0;
  const [display, setDisplay] = useState(0);
  const fromRef = useRef(0);

  useEffect(() => {
    const from = fromRef.current;

    if (prefersReducedMotion() || Math.abs(target - from) < 0.01) {
      fromRef.current = target;
      setDisplay(target);
      return undefined;
    }

    let frame = 0;
    const start = performance.now();

    const tick = (now) => {
      const progress = Math.min(Math.max((now - start) / duration, 0), 1);
      const eased = 1 - (1 - progress) ** 3;
      const next = from + (target - from) * eased;
      fromRef.current = next;
      setDisplay(next);

      if (progress < 1) frame = requestAnimationFrame(tick);
    };

    frame = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(frame);
  }, [target, duration]);

  if (format) return format(display);
  return Math.round(display).toLocaleString('en-PH');
}
