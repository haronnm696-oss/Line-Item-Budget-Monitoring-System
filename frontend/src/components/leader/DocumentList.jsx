import { useEffect, useState } from 'react';
import { apiUrl, fetchDocuments } from '../../api/leader';
import { formatDate } from '../../lib/datetime';

const TYPE_LABEL = {
  receipt: 'Receipt',
  invoice: 'Invoice',
  quotation: 'Quotation',
  payroll_file: 'Payroll file',
  other: 'Other',
};

function sizeLabel(bytes) {
  const n = Number(bytes) || 0;
  if (n >= 1024 * 1024) return `${(n / (1024 * 1024)).toFixed(1)} MB`;
  return `${Math.max(1, Math.round(n / 1024))} KB`;
}

/**
 * Documents of one record with previews (images inline, PDFs in a frame)
 * and download links. `type` is expense | payroll | purchase_request | approval.
 * A document whose file is missing (available: false) shows "File not
 * available" instead of a broken preview; its row is kept.
 */
export default function DocumentList({ type, id }) {
  const key = `${type}-${id}`;
  const [result, setResult] = useState(null); // { key, documents } | { key, error }
  const [preview, setPreview] = useState(null);

  useEffect(() => {
    let alive = true;
    fetchDocuments(type, id)
      .then((data) => { if (alive) setResult({ key, documents: Array.isArray(data?.documents) ? data.documents : [] }); })
      .catch(() => { if (alive) setResult({ key, error: 'Could not load the documents. Please try again.' }); });
    return () => { alive = false; };
  }, [type, id, key]);

  if (result?.key !== key) return <p className="ld-muted" aria-busy="true">Loading documents…</p>;
  if (result.error) return <p className="ld-muted" role="alert">{result.error}</p>;
  if (result.documents.length === 0) return <p className="ld-muted">No documents attached.</p>;

  return (
    <div className="ld-docs">
      <ul className="ld-doc-list">
        {result.documents.map((doc) => {
          const missing = doc.available === false;
          const canPreview = !missing && (doc.mime === 'application/pdf' || String(doc.mime).startsWith('image/'));
          const open = preview?.id === doc.id;
          return (
            <li key={doc.id} className="ld-doc">
              <div className="ld-doc-main">
                <b title={doc.file_name}>{doc.file_name}</b>
                <span>
                  {TYPE_LABEL[doc.document_type] ?? doc.document_type} · {sizeLabel(doc.size)}
                  {doc.uploaded_by ? ` · ${doc.uploaded_by.name}` : ''} · {formatDate(doc.uploaded_at)}
                </span>
              </div>
              <div className="ld-doc-actions">
                {canPreview && (
                  <button type="button" className="program-btn sm" aria-expanded={open} onClick={() => setPreview(open ? null : doc)}>
                    {open ? 'Hide' : 'Preview'}
                  </button>
                )}
                {missing
                  ? <span className="ld-file-missing" role="note"><span aria-hidden="true">⊘</span> File not available</span>
                  : <a className="program-btn sm" href={apiUrl(doc.download_url)}>Download</a>}
              </div>
            </li>
          );
        })}
      </ul>

      {preview && (
        <div className="ld-preview">
          {String(preview.mime).startsWith('image/') ? (
            <img src={apiUrl(`${preview.download_url}?inline=1`)} alt={preview.file_name} />
          ) : (
            <iframe src={apiUrl(`${preview.download_url}?inline=1`)} title={`Preview of ${preview.file_name}`} />
          )}
        </div>
      )}
    </div>
  );
}
