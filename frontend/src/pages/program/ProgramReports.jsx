import { useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { fetchProgramOverview } from '../../api/program';
import Reports from '../Reports';

export default function ProgramReports() {
  const [searchParams] = useSearchParams();
  const [funders, setFunders] = useState([]);
  const [funder, setFunder] = useState('');
  const executiveRef = useRef(null);
  const wantsExecutive = searchParams.get('report') === 'executive';

  // Funding-source options come from the Overview payload, so the values
  // match the Overview's filter exactly.
  useEffect(() => {
    let alive = true;
    fetchProgramOverview()
      .then((payload) => {
        if (alive && Array.isArray(payload?.funders)) setFunders(payload.funders);
      })
      .catch(() => {
        // The select still offers "All funders"; the report view reports
        // its own loading errors.
      });
    return () => { alive = false; };
  }, []);

  // ?report=executive (the Overview's "Executive report" button) opens the
  // page on the Executive report card.
  useEffect(() => {
    if (!wantsExecutive || !executiveRef.current) return;
    executiveRef.current.scrollIntoView({ block: 'start' });
    executiveRef.current.focus({ preventScroll: true });
  }, [wantsExecutive]);

  const reportQuery = funder ? `?funder=${encodeURIComponent(funder)}` : '';
  const previewUrl = `/program-reports/executive${reportQuery}`;
  const printUrl = `/program-reports/executive${reportQuery ? `${reportQuery}&` : '?'}print=1`;

  return (
    <>
      <div className="program-page-head">
        <div>
          <div className="program-eyebrow">Reports · Program Leader</div>
          <h1 className="program-h1">Reports</h1>
          <p className="program-sub">Reporting across all projects, for every funder.</p>
        </div>
      </div>

      <section
        className={`program-card${wantsExecutive ? ' is-selected' : ''}`}
        id="executive-report"
        ref={executiveRef}
        tabIndex={-1}
        aria-labelledby="executive-report-title"
      >
        <div className="program-card-head">
          <div>
            <div className="program-card-title" id="executive-report-title">Executive report</div>
            <div className="program-card-sub">
              Program-wide summary of all projects and funders, for printing or submission.
            </div>
          </div>
        </div>

        <div className="program-filter-row">
          <div className="program-field">
            <label htmlFor="executive-funder">Funding source</label>
            <select
              id="executive-funder"
              className="program-select"
              value={funder}
              onChange={(event) => setFunder(event.target.value)}
            >
              <option value="">All funders</option>
              {funders.map((agency) => (
                <option key={agency.id} value={agency.id}>{agency.label}</option>
              ))}
            </select>
          </div>
          <span className="spacer" />
          <Link className="program-btn" to={previewUrl}>Preview</Link>
          <Link className="program-btn primary" to={printUrl}>Print / Save as PDF</Link>
        </div>
      </section>

      <section className="program-card">
        <Reports programMode initialCode={searchParams.get('project') ?? ''} />
      </section>
    </>
  );
}
