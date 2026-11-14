import { Component } from 'react';

/**
 * Catches a render crash in one routed page so the Program Leader sidebar and
 * header stay usable. ProgramLayout keys it by pathname, so navigating to
 * another page clears the error.
 */
export default class PageErrorBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = { hasError: false };
  }

  static getDerivedStateFromError() {
    return { hasError: true };
  }

  componentDidCatch(error, info) {
    console.error('Program page crashed:', error, info?.componentStack);
  }

  render() {
    if (!this.state.hasError) return this.props.children;

    return (
      <section className="program-card pa-empty" role="alert">
        <span>Something went wrong on this page.</span>
        <button
          type="button"
          className="program-btn primary"
          onClick={() => window.location.reload()}
        >
          Reload page
        </button>
      </section>
    );
  }
}
