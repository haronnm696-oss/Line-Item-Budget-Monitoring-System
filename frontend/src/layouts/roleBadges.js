import { createContext, useContext } from 'react';

/**
 * Sidebar badge counts of the role shell. `refreshBadges()` reloads them
 * (after a decision); `setBadges()` takes counts a page already has (for
 * example the response of "My requests seen").
 */
export const RoleBadgesContext = createContext({
  badges: {},
  refreshBadges: () => {},
  setBadges: () => {},
});

export function useRoleBadges() {
  return useContext(RoleBadgesContext);
}
