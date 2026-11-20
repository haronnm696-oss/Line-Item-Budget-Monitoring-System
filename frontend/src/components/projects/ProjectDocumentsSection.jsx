import { useState } from 'react';
import { ENDPOINTS } from '../../api/endpoints';
import { apiUrl } from '../../api/leader';
import client from '../../api/client';
import { performWrite } from '../../lib/apiResult';
import { useApiData } from '../../lib/useApiData';
import { formatDate } from '../../lib/datetime';
import { PROJECT_DOCUMENT_TYPES } from '../../lib/projectAgreement';
import { Dialog, Field, RemoveDialog } from '../program/ProjectSetup';
import LoadState from '../LoadState';

function sizeLabel(bytes) {
  const n = Number(bytes) || 0;
  if (n >= 1024 * 1024) return `${(n / (1024 * 1024)).toFixed(1)} MB`;
  return `${Math.max(1, Math.round(n / 1024))} KB`;
}

const TYPE_LABEL = Object.fromEntries(PROJECT_DOCUMENT_TYPES);

/**
 * Project documents: the MOA and Forms A (proposal), B (line-item budget)
 * and C (work plan), plus other files. The server decides what each role
 * gets (GET /api/projects/:id/project-documents):
 *   manage    Program Leader: upload, remove, preview, download
 *   download  Project Leader, Finance/Admin, Researcher, Research Assistant:
 *             preview and download
 */
export default function ProjectDocumentsSection({ projectId }) {
  const list = useApiData(projectId ? ENDPOINTS.projectSetupDocuments(projectId) : null);
  const [dialog, setDialog] = useState(null); // { kind: 'upload' } | { kind: 'remove', doc }
  const [preview, setPreview] = useState(null);
  const [notice, setNotice] = useState('');

  if (list.status === 'error' && list.error?.status === 403) return null;

  const access = list.data?.access;
  const documents = Array.isArray(list.data?.documents) ? list.data.documents : [];
  const manage = access === 'manage';
  const canDownload = access === 'manage' || access === 'download';

  function done(message) {
    setDialog(null);
    setPreview(null);
    setNotice(message);
    list.retry();
  }

  return (
    <section className="program-card" aria-labelledby="project-docs-title">
      <div className="program-card-head">
        <div>
          <div className="program-card-title" id="project-docs-title">Project documents</div>
          <div className="program-card-sub">
            Memorandum of Agreement, Form A (proposal), Form B (line-item budget) and Form C (work plan).
            {access === 'download' && ' The Program Leader uploads these.'}
          </div>
        </div>
        {manage && (
          <button type="button" className="program-btn" onClick={() => { setNotice(''); setDialog({ kind: 'upload' }); }}>Upload document</button>
        )}
      </div>
      <div aria-live="polite">{notice && <div className="program-banner ok">{notice}</div>}</div>

      <LoadState state={list} loadingText="Loading project documents…" emptyText="No project documents yet." isEmpty={documents.length === 0}>
        <div className="ld-docs">
          <ul className="ld-doc-list">
            {documents.map((doc) => {
              const missing = doc.available === false;
              const canPreview = !missing && (doc.mime === 'application/pdf' || String(doc.mime).startsWith('image/'));
              const open = preview?.id === doc.id;
              return (
                <li key={doc.id} className="ld-doc">
                  <div className="ld-doc-main">
                    <b title={doc.file_name}>{doc.file_name}</b>
                    <span>
                      {doc.type_label ?? TYPE_LABEL[doc.document_type] ?? doc.document_type} · {sizeLabel(doc.size)}
                      {doc.uploaded_by ? ` · ${doc.uploaded_by.name}` : ''} · {formatDate(doc.uploaded_at)}
                    </span>
                  </div>
                  <div className="ld-doc-actions">
                    {canPreview && (
                      <button type="button" className="program-btn sm" aria-expanded={open} onClick={() => setPreview(open ? null : doc)}>
                        {open ? 'Hide' : 'Preview'}<span className="pa-sr-only"> {doc.file_name}</span>
                      </button>
                    )}
                    {missing && <span className="ld-file-missing" role="note"><span aria-hidden="true">⊘</span> File not available</span>}
                    {!missing && canDownload && (
                      <a className="program-btn sm" href={apiUrl(doc.download_url)}>Download<span className="pa-sr-only"> {doc.file_name}</span></a>
                    )}
                    {manage && (
                      <button type="button" className="program-btn sm danger" onClick={() => { setNotice(''); setDialog({ kind: 'remove', doc }); }}>
                        Remove<span className="pa-sr-only"> {doc.file_name}</span>
                      </button>
                    )}
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
      </LoadState>

      {dialog?.kind === 'upload' && <UploadDialog projectId={projectId} onClose={() => setDialog(null)} onSaved={done} />}
      {dialog?.kind === 'remove' && (
        <RemoveDialog
          title={`Remove ${dialog.doc.file_name}`}
          text="The file is removed from the project documents. This is recorded in the audit log."
          url={ENDPOINTS.programProjectDocument(dialog.doc.id)}
          onClose={() => setDialog(null)}
          onSaved={() => done('Document removed.')}
        />
      )}
    </section>
  );
}

/** Upload one project document (PDF, JPG or PNG, up to 5 MB). */
function UploadDialog({ projectId, onClose, onSaved }) {
  const [type, setType] = useState('');
  const [file, setFile] = useState(null);
  const [errors, setErrors] = useState({});
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  async function submit(event) {
    event.preventDefault();
    const next = {};
    if (!type) next.document_type = 'Choose the document type.';
    if (!file) next.file = 'Choose a file.';
    else if (file.size > 5 * 1024 * 1024) next.file = 'The file is too large. The limit is 5 MB per file.';
    setErrors(next);
    if (Object.keys(next).length) return;

    const body = new FormData();
    body.append('file', file);
    body.append('document_type', type);
    setBusy(true);
    setError('');
    try {
      await performWrite(() => client.post(ENDPOINTS.programProjectDocuments(projectId), body));
    } catch (failure) {
      const fields = failure?.fieldErrors ?? {};
      setErrors(Object.fromEntries(Object.entries(fields).map(([key, list]) => [key, Array.isArray(list) ? list[0] : String(list)])));
      setError(Object.keys(fields).length ? '' : (failure?.cause?.response?.data?.message || 'Could not save. Please try again.'));
      setBusy(false);
      return;
    }
    setBusy(false);
    onSaved('Document uploaded.');
  }

  return (
    <Dialog title="Upload project document" labelId="pd-doc-upload-title" busy={busy} onClose={onClose}>
      <form className="pu-form" onSubmit={submit} noValidate>
        {error && <div className="program-banner error" role="alert">{error}</div>}
        <div className="program-form-grid">
          <Field id="pd-doc-type" label="Document type" error={errors.document_type}>
            {(a11y) => (
              <select {...a11y} className="program-select" value={type} disabled={busy} onChange={(event) => { setType(event.target.value); setErrors({ ...errors, document_type: undefined }); }}>
                <option value="">Choose a type</option>
                {PROJECT_DOCUMENT_TYPES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
              </select>
            )}
          </Field>
          <Field id="pd-doc-file" label="File" error={errors.file} hint="PDF, JPG or PNG, up to 5 MB">
            {(a11y) => (
              <input {...a11y} className="program-input" type="file" accept=".pdf,.jpg,.jpeg,.png" disabled={busy} onChange={(event) => { setFile(event.target.files?.[0] ?? null); setErrors({ ...errors, file: undefined }); }} />
            )}
          </Field>
        </div>
        <div className="program-modal-actions">
          <button type="button" className="program-btn" onClick={onClose} disabled={busy}>Cancel</button>
          <button type="submit" className="program-btn primary" disabled={busy}>{busy ? 'Uploading…' : 'Upload'}</button>
        </div>
      </form>
    </Dialog>
  );
}
