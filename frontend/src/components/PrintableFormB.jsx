import { CURRENT_PROJECT, libCategoriesPS, libCategoriesMOOE } from '../data/mockData';

function formatMoney(amount) {
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 2,
  }).format(amount);
}

// Builds the official MOST R&D Form B printable document as a standalone,
// print-ready HTML fragment (A4, letterhead, dual-funding table, signatories).
function buildFormBHTML(p, categoriesPS = libCategoriesPS, categoriesMOOE = libCategoriesMOOE) {
  const psAllocated = categoriesPS.reduce((sum, c) => sum + c.allocated, 0);
  const psSpent = categoriesPS.reduce((sum, c) => sum + c.spent, 0);
  const mooeAllocated = categoriesMOOE.reduce((sum, c) => sum + c.allocated, 0);
  const mooeSpent = categoriesMOOE.reduce((sum, c) => sum + c.spent, 0);
  const grandTotal = psAllocated + mooeAllocated;
  const grandSpent = psSpent + mooeSpent;
  const remaining = grandTotal - grandSpent;

  const counterpartFunds = p.counterpart_budget || 0;
  const mostFunds = p.funding_budget || 0;
  const totalFunds = counterpartFunds + mostFunds || p.total_budget || 0;

  const splitAmount = (allocated) => ({
    counterpart: (allocated * counterpartFunds) / (totalFunds || 1),
    most: (allocated * mostFunds) / (totalFunds || 1),
  });

  const money = (n) => formatMoney(n);

  const psRows = categoriesPS.map((c) => {
    const split = splitAmount(c.allocated);
    return `
      <tr>
        <td>${c.name}</td>
        <td class="r">${money(split.counterpart)}</td>
        <td class="r">${money(split.most)}</td>
      </tr>`;
  }).join('');

  const mooeRows = categoriesMOOE.map((c) => {
    const split = splitAmount(c.allocated);
    return `
      <tr>
        <td>${c.name}</td>
        <td class="r">${money(split.counterpart)}</td>
        <td class="r">${money(split.most)}</td>
      </tr>`;
  }).join('');

  const groupRow = (label, allocated) => {
    const split = splitAmount(allocated);
    return `
      <tr class="fb-subtotal">
        <td>${label}</td>
        <td class="r">${money(split.counterpart)}</td>
        <td class="r">${money(split.most)}</td>
      </tr>`;
  };

  return `
    <!DOCTYPE html>
    <html>
    <head>
      <meta charset="utf-8" />
      <title>Official MOST R&D Form B — ${p.title}</title>
      <style>
        @page { size: A4; margin: 14mm; }
        body { font-family: 'Public Sans', system-ui, sans-serif; font-size: 11px; color: #221D1A; line-height: 1.45; }
        #form-b-print-area { background: #ffffff; }
        @media print {
          body * { visibility: hidden; }
          #form-b-print-area, #form-b-print-area * { visibility: visible; }
          #form-b-print-area { position: absolute; left: 0; top: 0; width: 100%; }
        }
        .fb-letterhead { border-bottom: 3px solid #5E1B25; padding-bottom: 10px; margin-bottom: 14px; display: flex; gap: 12px; align-items: center; }
        .fb-seal { width: 40px; height: 40px; border-radius: 50%; background: radial-gradient(circle at 35% 35%, #C9A227, #8a6d12); display: inline-flex; align-items: center; justify-content: center; font-weight: 800; color: #3d2f06; font-size: 12px; flex-shrink: 0; }
        .fb-org h1 { font-size: 15px; font-weight: 800; margin: 0; color: #5E1B25; }
        .fb-org p { font-size: 9.5px; color: #6E655E; margin: 2px 0 0; }
        .fb-title { font-size: 16px; font-weight: 800; margin: 12px 0 2px; color: #5E1B25; }
        .fb-sub { font-size: 10.5px; color: #6E655E; margin-bottom: 10px; }
        .fb-meta { font-size: 10px; color: #6E655E; margin-bottom: 12px; display: flex; gap: 18px; flex-wrap: wrap; }
        .fb-section { font-weight: 700; font-size: 11.5px; margin: 12px 0 4px; color: #5E1B25; }
        table.fb-table { width: 100%; border-collapse: collapse; font-size: 10.5px; }
        table.fb-table th { background: #F7F6F2; color: #6E655E; font-weight: 600; text-align: left; padding: 6px 8px; border: 1px solid #CFC9BC; }
        table.fb-table td { padding: 5px 8px; border: 1px solid #E4E0D7; vertical-align: top; }
        table.fb-table td.r { text-align: right; }
        table.fb-table tbody tr:nth-child(even) td { background: #FAF9F7; }
        table.fb-table tr.fb-subtotal td { font-weight: 700; background: #F7F6F2 !important; }
        table.fb-table tr.fb-grand td { font-weight: 800; background: #E4E0D7 !important; }
        .fb-signatories { margin-top: 26px; }
        .fb-signatories h4 { font-size: 12px; font-weight: 800; color: #5E1B25; margin: 0 0 8px; }
        table.fb-sign { width: 100%; border-collapse: collapse; font-size: 10.5px; }
        table.fb-sign td { border: 1px solid #CFC9BC; padding: 8px; vertical-align: top; }
        .fb-footer { margin-top: 22px; font-size: 9px; color: #6E655E; border-top: 1px solid #CFC9BC; padding-top: 8px; }
      </style>
    </head>
    <body>
      <div class="fb-letterhead">
        <div class="fb-seal">MSU</div>
        <div class="fb-org">
          <h1>Ministry of Science and Technology - BARMM</h1>
          <p>Monitoring Agency · Mindanao State University–Main Campus</p>
          <p>Official R&D Form B · Line-Item Budget</p>
        </div>
      </div>

      <div class="fb-title">Project Line-Item Budget</div>
      <div class="fb-sub">Project: ${p.title} (${p.code})</div>

      <div class="fb-meta">
        <span><b>Project Title:</b> ${p.title}</span>
        <span><b>Implementing Agency:</b> ${p.implementing_agency}</span>
        <span><b>Monitoring Agency:</b> ${p.funding_agency}</span>
        <span><b>Duration:</b> ${p.period}</span>
        <span><b>Project Leader:</b> ${p.project_leader}</span>
      </div>

      <div class="fb-section">I. PERSONNEL SERVICES (PS) — Direct Cost &amp; Indirect Cost</div>
      <table class="fb-table">
        <thead>
          <tr>
            <th>Line Item Description</th>
            <th class="r">Implementing Agency (Counterpart Funding - MSU-Marawi)</th>
            <th class="r">MOST Funding Agency</th>
          </tr>
        </thead>
        <tbody>
          ${psRows}
          ${groupRow('PS Subtotal', psAllocated)}
        </tbody>
      </table>

      <div class="fb-section">II. MAINTENANCE AND OTHER OPERATING EXPENSES (MOOE) — Direct Cost &amp; Indirect Cost</div>
      <table class="fb-table">
        <thead>
          <tr>
            <th>Line Item Description</th>
            <th class="r">Implementing Agency (Counterpart Funding - MSU-Marawi)</th>
            <th class="r">MOST Funding Agency</th>
          </tr>
        </thead>
        <tbody>
          ${mooeRows}
          ${groupRow('MOOE Subtotal', mooeAllocated)}
        </tbody>
      </table>

      <table class="fb-table">
        <tbody>
          <tr class="fb-grand">
            <td>GRAND TOTAL</td>
            <td class="r">${money(counterpartFunds)}</td>
            <td class="r">${money(mostFunds)}</td>
          </tr>
        </tbody>
      </table>

      <div class="fb-signatories">
        <h4>Official Signatory Approval</h4>
        <table class="fb-sign">
          <tbody>
            <tr>
              <td>Reviewed by:</td>
              <td>______________________</td>
              <td>${p.project_leader}</td>
            </tr>
            <tr>
              <td>Certified Funds Available:</td>
              <td>______________________</td>
              <td>${money(remaining)}</td>
            </tr>
            <tr>
              <td>Recommending Approval:</td>
              <td>______________________</td>
              <td>${money(grandTotal)}</td>
            </tr>
            <tr>
              <td>Approved:</td>
              <td>______________________</td>
              <td>${money(grandTotal)}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="fb-footer">
        Ministry of Science and Technology - BARMM · MOST R&amp;D Form B · Line-Item Budget Monitoring System.<br />
        Generated from the LIB Monitoring System on ${new Date().toLocaleDateString('en-PH')}. Figures in Philippine Pesos (₱).
      </div>
    </body>
    </html>
  `;
}

export default function PrintableFormB({ open, onClose, project, categoriesPS, categoriesMOOE }) {
  if (!open) return null;
  const p = project || CURRENT_PROJECT;

  return (
    <div className="modal-overlay" onClick={onClose} role="dialog" aria-modal="true" aria-label="Official MOST R&D Form B preview">
      <div className="modal-panel" style={{ maxWidth: '860px', maxHeight: '95vh', display: 'flex', flexDirection: 'column' }} onClick={(e) => e.stopPropagation()}>
        <div className="modal-head">
          <h3>Official MOST R&D Form B — {p.title}</h3>
          <button type="button" className="modal-close" onClick={onClose} aria-label="Close">×</button>
        </div>
        <div id="form-b-print-area" style={{ flex: 1, overflow: 'auto', padding: '24px 28px', background: '#ffffff' }} dangerouslySetInnerHTML={{ __html: buildFormBHTML(p, categoriesPS, categoriesMOOE) }} />
        <div style={{ padding: '12px 20px', borderTop: '1px solid var(--line)', display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
          <button type="button" className="btn" onClick={onClose}>Close</button>
          <button type="button" className="btn primary" onClick={() => window.print()}>Print / Export PDF</button>
        </div>
      </div>
    </div>
  );
}
