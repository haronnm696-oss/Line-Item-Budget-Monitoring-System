import { useCallback, useEffect, useRef, useState } from 'react';
import { useAuth } from '../../context/AuthContext';
import { fetchProgramOverview, decideApproval } from '../../api/program';
import ApprovalInbox from '../../components/program/ApprovalInbox';
import ExceptionsPanel from '../../components/program/ExceptionsPanel';
import FundingSources from '../../components/program/FundingSources';
import ProgramKpis from '../../components/program/ProgramKpis';
import ProjectHealthMatrix from '../../components/program/ProjectHealthMatrix';
import RejectModal from '../../components/program/RejectModal';
import ReleaseForecast from '../../components/program/ReleaseForecast';
import ReportingCalendar from '../../components/program/ReportingCalendar';

function updatedLabel(value) {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  return date.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
}

function messageFor(error, fallback) {
  return error?.response?.data?.message || fallback;
}

export default function ProgramLeaderDashboard() {
  const { user } = useAuth();
  const [data, setData] = useState(null);
  const [funders, setFunders] = useState([]);
  const [funder, setFunder] = useState('');
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState('');
  const [feedback, setFeedback] = useState(null);
  const [busyApprovalId, setBusyApprovalId] = useState(null);
  const [rejectTarget, setRejectTarget] = useState(null);
  const [rejectBusy, setRejectBusy] = useState(false);
  const [rejectError, setRejectError] = useState('');
  const requestRef = useRef(0);

  const load = useCallback(async (selectedFunder, quiet = false) => {
    const requestId = ++requestRef.current;

    if (quiet) {
      setRefreshing(true);
    } else {
      setLoading(true);
      setData(null);
    }
    setError('');

    try {
      const payload = await fetchProgramOverview(selectedFunder);
      if (requestId !== requestRef.current) return;

      setData(payload);
      if (Array.isArray(payload.funders)) setFunders(payload.funders);
    } catch (requestError) {
      if (requestId !== requestRef.current) return;
      setError(messageFor(requestError, 'Unable to load the program dashboard right now.'));
    } finally {
      if (requestId === requestRef.current) {
        setLoading(false);
        setRefreshing(false);
      }
    }
  }, []);

  useEffect(() => {
    load(funder);
  }, [funder, load]);

  function handleFunderChange(event) {
    setFunder(event.target.value);
    setFeedback(null);
  }

  async function handleApprove(approval) {
    if (busyApprovalId) return;

    setBusyApprovalId(approval.id);
    setFeedback(null);

    try {
      await decideApproval(approval.id, { decision: 'approved' });
      setFeedback({ tone: 'ok', text: `Approved “${approval.title}”. Related records were updated.` });
      await load(funder, true);
    } catch (requestError) {
      setFeedback({
        tone: 'over',
        text: messageFor(requestError, 'Unable to approve this request. Please try again.'),
      });
    } finally {
      setBusyApprovalId(null);
    }
  }

  function openReject(approval) {
    setRejectError('');
    setRejectTarget(approval);
  }

  async function submitReject(remarks) {
    if (!rejectTarget || rejectBusy) return;

    setRejectBusy(true);
    setRejectError('');

    try {
      await decideApproval(rejectTarget.id, { decision: 'rejected', remarks });
      setFeedback({ tone: 'ok', text: `Rejected “${rejectTarget.title}”.` });
      setRejectTarget(null);
      await load(funder, true);
    } catch (requestError) {
      const validationError = requestError?.response?.data?.errors?.remarks?.[0];
      if (validationError) {
        setRejectError(validationError);
      } else {
        setRejectTarget(null);
        setFeedback({
          tone: 'over',
          text: messageFor(requestError, 'Unable to reject this request. Please try again.'),
        });
      }
    } finally {
      setRejectBusy(false);
    }
  }

  const hasData = Boolean(data);
  const isEmpty = hasData && Number(data.meta?.project_count ?? data.projects?.length ?? 0) === 0;

  return (
    <div className="dashboard pd-page">
      <div className="page-head pd-head">
        <div>
          <div className="pd-eyebrow">MOST R&amp;D · Program-wide oversight</div>
          <h2>Program Leader Dashboard</h2>
          <p>
            {user?.name ? `${user.name} · ` : ''}
            All projects, every funding source, one decision queue.
          </p>
        </div>

        <div className="pd-filter">
          <label htmlFor="pd-funder">Funding source</label>
          <select
            id="pd-funder"
            value={funder}
            onChange={handleFunderChange}
            disabled={loading && funders.length === 0}
          >
            <option value="">All funders</option>
            {funders.map((agency) => (
              <option value={agency.id} key={agency.id}>
                {agency.label} ({agency.project_count})
              </option>
            ))}
          </select>
          <span className="pd-updated">
            {refreshing
              ? 'Updating…'
              : data?.meta?.generated_at
                ? `Updated ${updatedLabel(data.meta.generated_at)}`
                : 'Live data'}
          </span>
        </div>
      </div>

      {error && (
        <div className="banner over pd-error">
          <span>{error}</span>
          <button type="button" className="btn sm" onClick={() => load(funder)}>
            Retry
          </button>
        </div>
      )}

      {feedback && <div className={`banner ${feedback.tone}`}>{feedback.text}</div>}

      {loading && !data ? (
        <div className="panel pd-loading">
          <span className="pd-loading-dot" aria-hidden="true" />
          Loading program data…
        </div>
      ) : isEmpty ? (
        <div className="panel pd-loading">
          No projects match the selected funding source.
        </div>
      ) : hasData ? (
        <>
          <ProgramKpis kpis={data.kpis} />

          <div className="grid pd-split">
            <ApprovalInbox
              approvals={data.approvals}
              busyId={busyApprovalId}
              onApprove={handleApprove}
              onReject={openReject}
            />
            <ReleaseForecast forecast={data.forecast} />
          </div>

          <ProjectHealthMatrix projects={data.projects} />

          <div className="grid pd-bottom">
            <FundingSources sources={data.funding_sources} />
            <ReportingCalendar deadlines={data.deadlines} />
          </div>

          <ExceptionsPanel exceptions={data.exceptions} />
        </>
      ) : null}

      <RejectModal
        key={rejectTarget?.id ?? 'closed'}
        approval={rejectTarget}
        busy={rejectBusy}
        error={rejectError}
        onClose={() => {
          if (!rejectBusy) setRejectTarget(null);
        }}
        onConfirm={submitReject}
      />
    </div>
  );
}
