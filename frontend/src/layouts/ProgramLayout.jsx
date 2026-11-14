import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { fetchProgramApprovals } from '../api/program';
import RoleShell from './RoleShell';
import { PROGRAM_NAV } from './roleNav';

const ProgramRefreshContext = createContext({
  pendingCount: 0,
  refreshPending: () => {},
});

export function useProgramRefresh() {
  return useContext(ProgramRefreshContext);
}

/** The Program Leader shell: the shared role shell plus the pending-approvals count. */
export default function ProgramLayout() {
  const [pendingCount, setPendingCount] = useState(0);

  const refreshPending = useCallback(async () => {
    try {
      const payload = await fetchProgramApprovals({ status: 'pending' });
      setPendingCount(Number(payload?.counts?.pending) || 0);
    } catch {
      // The badge is a convenience; a failed count must not break the shell.
    }
  }, []);

  useEffect(() => {
    refreshPending();
  }, [refreshPending]);

  return (
    <ProgramRefreshContext.Provider value={{ pendingCount, refreshPending }}>
      <RoleShell
        navGroups={PROGRAM_NAV}
        navLabel="Program Leader navigation"
        breadcrumb="Program: All Projects — MSU–Main Campus"
        badges={{ approvals: pendingCount }}
        badgeLabel={(key, count) => `${count} pending approvals`}
        bell={{ count: pendingCount, to: '/program-approvals', label: `${pendingCount} pending approvals` }}
        fallbackName="Program Leader"
      />
    </ProgramRefreshContext.Provider>
  );
}
