/**
 * Eyebrow and page title, with the "Read-only access" badge beside the
 * title on read-only (Researcher) pages, so every page puts it in the same
 * place. Goes inside `.pl-head > div`, before the project line or subtitle.
 */
export default function PageTitle({ eyebrow, title, readOnly = false }) {
  return (
    <>
      {eyebrow && <div className="program-eyebrow">{eyebrow}</div>}
      <h1 className={`program-h1${readOnly ? ' res-title' : ''}`}>
        {title}
        {readOnly && <span className="res-readonly"><span aria-hidden="true">👁</span> Read-only access</span>}
      </h1>
    </>
  );
}
