import { useState } from 'react';
import Panel from './Panel';

const REPORT_OPTIONS = [
  { value: 'form-b', label: 'MOST Form B — Line-Item Budget' },
  { value: 'form-c', label: 'MOST Form C — Financial & Fund Release' },
  { value: 'liquidation', label: 'Liquidation / Expense Summary' },
  { value: 'utilization', label: 'Budget Utilization Report' },
];

const COMPLIANCE_OPTIONS = [
  { value: 'all', label: 'All expenses' },
  { value: 'verified', label: 'Verified expenses only' },
  { value: 'missing_receipts', label: 'Missing receipts only' },
];

export default function ExportShortcuts({ downloading, onDownload, onPrintFormB }) {
  const [reportType, setReportType] = useState('form-b');
  const [compliance, setCompliance] = useState('all');

  const isLiquidation = reportType === 'liquidation';

  return (
    <Panel
      title="Quick Export & Compliance Filters"
      hint="Generate official monitoring reports"
      action={<span className="chip brand">Forms B &amp; C</span>}
    >
      <div className="export-body">
        <div className="field">
          <label htmlFor="export-report-type">Report</label>
          <select
            id="export-report-type"
            value={reportType}
            onChange={(event) => setReportType(event.target.value)}
          >
            {REPORT_OPTIONS.map((option) => (
              <option value={option.value} key={option.value}>{option.label}</option>
            ))}
          </select>
        </div>

        <div className="field">
          <label htmlFor="export-compliance">Compliance filter</label>
          <select
            id="export-compliance"
            value={compliance}
            onChange={(event) => setCompliance(event.target.value)}
            disabled={!isLiquidation}
          >
            {COMPLIANCE_OPTIONS.map((option) => (
              <option value={option.value} key={option.value}>{option.label}</option>
            ))}
          </select>
          <div className="export-hint">
            {isLiquidation
              ? 'Filters the liquidation ledger by receipt/verification status.'
              : 'Compliance filters apply to the liquidation summary.'}
          </div>
        </div>

        <div className="export-actions">
          <button
            type="button"
            className="btn primary"
            disabled={downloading}
            onClick={() => onDownload({ type: reportType, compliance })}
          >
            {downloading ? 'Preparing…' : 'Download CSV'}
          </button>
          <button type="button" className="btn" onClick={onPrintFormB}>
            Print Form B
          </button>
        </div>

        <p className="export-note">
          CSV files open directly in Excel. Use <b>Print Form B</b> for the official letterhead layout.
        </p>
      </div>
    </Panel>
  );
}
