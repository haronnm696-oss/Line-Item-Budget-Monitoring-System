import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { fetchProgramOverview } from '../../api/program';
import Reports from '../Reports';

export default function ProgramReports() {
  const [searchParams] = useSearchParams();
  const [funders, setFunders] = useState([]);
  const [funder, setFunder] = useState('');

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

  // The printable report opens with ?print=1, so the browser's
  // "Save as PDF" dialog comes up once it has rendered.
  const downloadUrl = `/program-reports/executive?${funder ? `funder=${encodeURIComponent(funder)}&` : ''}print=1`;

  return (
    <>
      <div className="program-page-head">
        <div>
          <h1 className="program-h1">Reports</h1>
        </div>
      </div>

      <section className="program-card" id="executive-report" aria-labelledby="executive-report-title">
        <div className="program-card-head">
          <div>
            <div className="program-card-title" id="executive-report-title">Executive report</div>
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
          <Link className="program-btn primary" to={downloadUrl}>
            Download PDF<span className="pa-sr-only"> of the executive report</span>
          </Link>
        </div>
      </section>

      <section className="program-card">
        <Reports programMode initialCode={searchParams.get('project') ?? ''} />
      </section>
    </>
  );
}
