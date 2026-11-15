/**
 * Loading, error ("Try again") and empty states for pages that show real data.
 * Renders children only when the data is ready and not empty.
 */
export default function LoadState({ state, loadingText = 'Loading…', emptyText = 'Nothing to show yet.', isEmpty = false, children }) {
  if (state.status === 'idle' || state.status === 'loading') {
    return <div className="program-card pa-empty" aria-busy="true">{loadingText}</div>;
  }
  if (state.status === 'error') {
    return (
      <div className="program-card pa-empty" role="alert">
        <span>{state.error?.message ?? 'Could not load this data. Please try again.'}</span>
        <button type="button" className="program-btn sm" onClick={state.retry}>Try again</button>
      </div>
    );
  }
  if (isEmpty) {
    return <div className="program-card pa-empty">{emptyText}</div>;
  }
  return children;
}
