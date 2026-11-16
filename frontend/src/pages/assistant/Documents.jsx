import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ENDPOINTS } from '../../api/endpoints';
import { apiUrl } from '../../api/leader';
import { useApiData } from '../../lib/useApiData';
import { useAssignedProject } from '../../lib/useAssignedProject';
import { useUrlQuery } from '../../lib/useUrlQuery';
import { RECORD_PAGE } from '../../lib/assistantStatus';
import { formatDate } from '../../lib/datetime';
import LoadState from '../../components/LoadState';
import ProjectSelect from '../../components/leader/ProjectSelect';

const DOCUMENT_TYPES = [
  ['', 'All document types'],
  ['receipt', 'Receipt'],
  ['invoice', 'Invoice'],
  ['quotation', 'Quotation'],
  ['payroll_file', 'Payroll file'],
  ['other', 'Other'],
];
const RECORDS = [
  ['', 'All records'],
  ['expense', 'Expenses'],
  ['purchase_request', 'Purchase requests'],
  ['payroll', 'Payroll'],
];
const TYPE_LABEL = Object.fromEntries(DOCUMENT_TYPES);

function sizeLabel(bytes) {
  const n = Number(bytes) || 0;
  if (n >= 1024 * 1024) return `${(n / (1024 * 1024)).toFixed(1)} MB`;
  return `${Math.max(1, Math.round(n / 1024))} KB`;
}

/**
 * Documents (Research Assistant): every document of the project's
 * expenses, purchase requests and payroll, with type and record filters in
 * the URL, an inline preview (image / PDF) and download.
 */
export default function Documents() {
  const picker = useAssignedProject({ role: 'research-assistant' });
  const { get, update } = useUrlQuery();
  const documentType = DOCUMENT_TYPES.some(([value]) => value === get('type')) ? get('type') : '';
  const recordType = RECORDS.some(([value]) => value === get('record')) ? get('record') : '';
  const [preview, setPreview] = useState(null);

  const query = new URLSearchParams({
    ...(documentType ? { document_type: documentType } : {}),
    ...(recordType ? { record: recordType } : {}),
  }).toString();
  const list = useApiData(picker.projectId ? `${ENDPOINTS.projectDocuments(picker.projectId)}${query ? `?${query}` : ''}` : null);
  const documents = Array.isArray(list.data?.documents) ? list.data.documents : [];
  const filtered = Boolean(documentType || recordType);

  function recordLink(record) {
    const page = RECORD_PAGE[record.type];
    return `${page}?project=${picker.projectId}&open=${record.type}-${record.id}`;
  }

  return (
    <div className="ld-page">
      <div className="pl-head">
        <div>
          <div className="program-eyebrow">Encode · Research Assistant</div>
          <h1 className="program-h1">Documents</h1>
          <p className="program-sub">
            {picker.project ? `${picker.project.title} · ${picker.project.code} · ` : ''}Receipts, quotations, invoices and payroll files of the project
          </p>
        </div>
        <div className="pl-head-actions"><ProjectSelect picker={picker} id="ra-documents-project" /></div>
      </div>

      <LoadState state={picker.state} emptyText="You are not assigned to a project as a Research Assistant yet." isEmpty={picker.state.status === 'ready' && !picker.project}>
        <div className="program-filter-row ld-controls">
          <div className="program-field">
            <label htmlFor="ra-doc-type">Document type</label>
            <select id="ra-doc-type" className="program-select" value={documentType} onChange={(event) => { setPreview(null); update({ type: event.target.value }); }}>
              {DOCUMENT_TYPES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
          </div>
          <div className="program-field">
            <label htmlFor="ra-doc-record">Record</label>
            <select id="ra-doc-record" className="program-select" value={recordType} onChange={(event) => { setPreview(null); update({ record: event.target.value }); }}>
              {RECORDS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
          </div>
          {filtered && (
            <button type="button" className="program-btn" onClick={() => { setPreview(null); update({ type: '', record: '' }); }}>Clear filters</button>
          )}
        </div>

        <LoadState
          state={list}
          loadingText="Loading documents…"
          emptyText={filtered ? 'No document matches these filters.' : 'No documents yet. Attach receipts and quotations when you encode.'}
          isEmpty={documents.length === 0}
        >
          <div className="pl-row pl-row-main ra-docs-layout">
            <section className="program-card ld-list-card">
              <ul className="ld-doc-list">
                {documents.map((doc) => {
                  const canPreview = doc.mime === 'application/pdf' || String(doc.mime).startsWith('image/');
                  const showing = preview?.id === doc.id;
                  return (
                    <li key={doc.id} className={`ld-doc${showing ? ' ra-doc-on' : ''}`}>
                      <div className="ld-doc-main">
                        <b title={doc.file_name}>{doc.file_name}</b>
                        <span>
                          {TYPE_LABEL[doc.document_type] ?? doc.document_type} · {sizeLabel(doc.size)}
                          {doc.uploaded_by ? ` · ${doc.uploaded_by.name}` : ''} · {formatDate(doc.uploaded_at)}
                        </span>
                        {doc.record && (
                          <span>
                            {doc.record.type_label}{' '}
                            <Link to={recordLink(doc.record)}>{doc.record.reference}</Link> · {doc.record.title}
                          </span>
                        )}
                      </div>
                      <div className="ld-doc-actions">
                        {canPreview && (
                          <button type="button" className="program-btn sm" aria-pressed={showing} onClick={() => setPreview(showing ? null : doc)}>
                            {showing ? 'Hide' : 'Preview'}
                          </button>
                        )}
                        <a className="program-btn sm" href={apiUrl(doc.download_url)}>Download</a>
                      </div>
                    </li>
                  );
                })}
              </ul>
            </section>

            <section className="program-card ra-preview-card" aria-live="polite">
              <div className="program-card-head">
                <div>
                  <div className="program-card-title">Preview</div>
                  <div className="program-card-sub">{preview ? preview.file_name : 'Choose Preview on an image or PDF'}</div>
                </div>
              </div>
              {preview ? (
                <div className="ld-preview">
                  {String(preview.mime).startsWith('image/') ? (
                    <img src={apiUrl(`${preview.download_url}?inline=1`)} alt={preview.file_name} />
                  ) : (
                    <iframe src={apiUrl(`${preview.download_url}?inline=1`)} title={`Preview of ${preview.file_name}`} />
                  )}
                </div>
              ) : (
                <p className="ld-empty">Nothing selected.</p>
              )}
            </section>
          </div>
        </LoadState>
      </LoadState>
    </div>
  );
}
