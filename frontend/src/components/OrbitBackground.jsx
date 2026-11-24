import '../styles/orbit-background.css';

// Decorative animated backdrop for the Login page only.
// Purely visual: aria-hidden, pointer-events: none, CSS-only animation.

const ICONS = {
  peso: (
    <>
      <path d="M8 20V4h5a4 4 0 0 1 0 8H8" />
      <path d="M5 7h14M5 10h14" />
    </>
  ),
  doc: (
    <>
      <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8Z" />
      <path d="M14 3v5h5" />
      <path d="M9 13h6M9 17h4" />
    </>
  ),
  receipt: (
    <>
      <path d="M6 3h12v18l-2-1.5-2 1.5-2-1.5-2 1.5-2-1.5L6 21Z" />
      <path d="M9 8h6M9 12h6M9 16h3" />
    </>
  ),
  check: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="m8 12.5 2.8 2.8L16 9.8" />
    </>
  ),
  chart: (
    <>
      <path d="M4 20h16" />
      <path d="M7 16v-4M12 16V7M17 16v-7" />
    </>
  ),
  bell: (
    <>
      <path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15Z" />
      <path d="M10 20.5a2 2 0 0 0 4 0" />
    </>
  ),
  users: (
    <>
      <circle cx="9" cy="8.5" r="3" />
      <path d="M3.5 19a5.5 5.5 0 0 1 11 0" />
      <circle cx="16.5" cy="9.5" r="2.5" />
      <path d="M15.5 14.2A4.8 4.8 0 0 1 20.5 19" />
    </>
  ),
};

// size: ring diameter in px; dur: seconds per turn; bubbles: [angle°, icon, variant]
const RINGS = [
  {
    size: 1120,
    dur: 90,
    bubbles: [
      [20, 'peso', 'gold'],
      [110, 'check'],
      [200, 'doc'],
    ],
  },
  {
    size: 1420,
    dur: 130,
    reverse: true,
    dashed: true,
    bubbles: [
      [60, 'bell', 'glass'],
      [250, 'receipt', 'glass'],
    ],
  },
  {
    size: 1760,
    dur: 180,
    bubbles: [
      [150, 'chart'],
      [320, 'users', 'glass'],
    ],
  },
  {
    size: 2100,
    dur: 240,
    reverse: true,
    outer: true,
    bubbles: [],
  },
];

const DOTS = ['orbit-bg__dot--1', 'orbit-bg__dot--2', 'orbit-bg__dot--3', 'orbit-bg__dot--4'];

function bubblePosition(angle) {
  const rad = (angle * Math.PI) / 180;
  return {
    left: `${50 + 50 * Math.cos(rad)}%`,
    top: `${50 - 50 * Math.sin(rad)}%`,
  };
}

function Bubble({ angle, icon, variant }) {
  const className = variant ? `orbit-bg__bubble orbit-bg__bubble--${variant}` : 'orbit-bg__bubble';

  return (
    <span className={className} style={bubblePosition(angle)}>
      <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
        focusable="false"
      >
        {ICONS[icon]}
      </svg>
    </span>
  );
}

export default function OrbitBackground() {
  return (
    <div className="orbit-bg" aria-hidden="true">
      <span className="orbit-bg__glow orbit-bg__glow--gold" />
      <span className="orbit-bg__glow orbit-bg__glow--rose" />

      {RINGS.map((ring) => (
        <div
          key={ring.size}
          className={[
            'orbit-bg__ring',
            ring.dashed && 'orbit-bg__ring--dashed',
            ring.outer && 'orbit-bg__ring--outer',
          ]
            .filter(Boolean)
            .join(' ')}
          style={{
            '--orbit-size': `${ring.size}px`,
            '--orbit-dur': `${ring.dur}s`,
            '--orbit-dir': ring.reverse ? 'reverse' : 'normal',
          }}
        >
          {ring.bubbles.map(([angle, icon, variant]) => (
            <Bubble key={angle} angle={angle} icon={icon} variant={variant} />
          ))}
        </div>
      ))}

      {DOTS.map((dot) => (
        <span key={dot} className={`orbit-bg__dot ${dot}`} />
      ))}
    </div>
  );
}
