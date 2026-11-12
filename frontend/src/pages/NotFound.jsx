import { Link } from 'react-router-dom';

export default function NotFound() {
  return (
    <div className="notfound-page">
      <a className="landing-skip" href="#notfound-main">Skip to content</a>
      <main id="notfound-main" className="notfound-card">
        <img
          src="/images/msu-seal.png"
          alt="Mindanao State University seal"
          width="293"
          height="300"
        />
        <p className="notfound-code">404</p>
        <h1>Page not found</h1>
        <p>We couldn’t find the page you were looking for.</p>
        <Link to="/" className="landing-pill landing-pill--solid">Back to home</Link>
      </main>
    </div>
  );
}
