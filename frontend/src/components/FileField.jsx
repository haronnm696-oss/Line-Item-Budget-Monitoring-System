import { useRef, useState } from 'react';
import { prepareUpload } from '../lib/compressImage';
import { FILE_ACCEPT, FILE_HINT } from '../lib/fileRules';
import { formatBytes } from '../lib/imagePrep';

/**
 * File input used by every upload control. Photos (JPG / PNG) are resized
 * and saved as JPEG in the browser before upload; PDFs go as they are. The
 * final size of each file is shown before anything is sent.
 *
 * onChange(value, problem): value is the prepared File (or File[] with
 * `multiple`), null / [] when nothing usable was chosen; problem is the
 * message to show, or null. onPreparing(bool) is optional (disable submit
 * while photos are being compressed).
 */
export default function FileField({ id, label, multiple = false, disabled = false, onChange, onPreparing, hint = FILE_HINT }) {
  const [items, setItems] = useState([]);
  const [preparing, setPreparing] = useState(false);
  const run = useRef(0);

  async function choose(event) {
    const input = event.target;
    const chosen = Array.from(input.files ?? []);
    const attempt = ++run.current;
    setItems([]);
    if (chosen.length === 0) {
      onChange(multiple ? [] : null, null);
      return;
    }

    setPreparing(true);
    onPreparing?.(true);
    onChange(multiple ? [] : null, null);
    const results = await Promise.all(chosen.map((file) => prepareUpload(file)));
    if (attempt !== run.current) return; // a newer choice replaced this one
    setPreparing(false);
    onPreparing?.(false);

    const failed = results.find((result) => result.error);
    if (failed) {
      input.value = '';
      onChange(multiple ? [] : null, failed.error);
      return;
    }
    setItems(results.map((result, index) => ({ ...result, originalName: chosen[index].name })));
    const files = results.map((result) => result.file);
    onChange(multiple ? files : files[0], null);
  }

  return (
    <>
      <label htmlFor={id}>{label} <span className="ld-muted">· {hint}{multiple ? ' each' : ''}</span></label>
      <input id={id} type="file" accept={FILE_ACCEPT} multiple={multiple} onChange={choose} disabled={disabled} aria-describedby={`${id}-sizes`} />
      <div id={`${id}-sizes`} aria-live="polite">
        {preparing && <div className="ld-hint">Preparing… photos are resized before upload.</div>}
        {!preparing && items.map((item) => (
          <div key={item.originalName} className="ld-hint file-size-line">
            <b>{item.file.name}</b>{' · '}
            {item.finalSize < item.originalSize
              ? <>{formatBytes(item.originalSize)} → <b>{formatBytes(item.finalSize)}</b></>
              : <b>{formatBytes(item.finalSize)}</b>}
            {item.note ? ` · ${item.note}` : ''}
          </div>
        ))}
      </div>
    </>
  );
}
