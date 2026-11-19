import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { fetchProgramApprovals, fetchRegistrationCount } from '../api/program';
import RoleShell from './RoleShell';
import { PROGRAM_NAV } from './roleNav';

const POLL_MS = 60000;

const ProgramRefreshContext = createContext({
  pendingCount: 0,
  refreshPending: () => {},
  registrationCount: 0,
  refreshRegistrations: () => {},
});

export function useProgramRefresh() {
  return useContext(ProgramRefreshContext);
}

const BADGE_LABELS = {
  approvals: (count) => `${count} pending approvals`,
  registrations: (count) => `${count} pending registration${count === 1 ? '' : 's'}`,
};

/**
 * The Program Leader shell: the shared role shell plus two menu badges,
 * pending approvals and pending registrations (Users and roles). Both load
 * on mount, refresh on focus and every minute, and pages refresh them after
 * a decision.
 */
export default function ProgramLayout() {
  const [pendingCount, setPendingCount] = useState(0);
  const [registrationCount, setRegistrationCount] = useState(0);

  // A badge is a convenience; a failed count must not break the shell.
  const refreshPending = useCallback(async () => {
    try {
      const payload = await fetchProgramApprovals({ status: 'pending' });
      setPendingCount(Number(payload?.counts?.pending) || 0);
    } catch { /* keep the last value */ }
  }, []);

  const refreshRegistrations = useCallback(async () => {
    try {
      const payload = await fetchRegistrationCount();
      setRegistrationCount(Number(payload?.counts?.pending) || 0);
    } catch { /* keep the last value */ }
  }, []);

  useEffect(() => {
    refreshPending();
    refreshRegistrations();
    const timer = window.setInterval(() => {
      if (document.visibilityState === 'visible') refreshRegistrations();
    }, POLL_MS);
    window.addEventListener('focus', refreshRegistrations);
    return () => {
      window.clearInterval(timer);
      window.removeEventListener('focus', refreshRegistrations);
    };
  }, [refreshPending, refreshRegistrations]);

  return (
    <ProgramRefreshContext.Provider value={{ pendingCount, refreshPending, registrationCount, refreshRegistrations }}>
      <RoleShell
        navGroups={PROGRAM_NAV}
        navLabel="Program Leader navigation"
        breadcrumb="Program: All Projects — MSU–Main Campus"
        badges={{ approvals: pendingCount, registrations: registrationCount }}
        badgeLabel={(key, count) => (BADGE_LABELS[key] ?? BADGE_LABELS.approvals)(count)}
        fallbackName="Program Leader"
      />
    </ProgramRefreshContext.Provider>
  );
}
