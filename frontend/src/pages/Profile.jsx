import { useEffect, useRef, useState } from 'react';
import { useAuth } from '../context/AuthContext';
import UserAvatar from '../components/UserAvatar';
import FileField from '../components/FileField';
import { removeAvatar, uploadAvatar } from '../api/profile';
import { failureMessage } from '../lib/apiResult';
import {
  AVATAR_ACCEPT,
  AVATAR_HINT,
  AVATAR_READ_ERROR,
  avatarFileError,
  prepareAvatarFile,
  centeredOffset,
  clampOffset,
  coverScale,
  cropRect,
  zoomOffset,
} from '../lib/avatar';

const FRAME = 240; // crop square on screen (px)
const OUTPUT = 512; // JPEG sent to the server (it makes the final 256x256)
const MAX_ZOOM = 3;
const STEP = 10; // arrow-key move (px)
const TOAST_MS = 3000; // success toast stays this long

/** A rejected file's message, with the type/size rule (shown only here). */
function rejectedText(problem) {
  return `${problem} Allowed: ${AVATAR_HINT}.`;
}

function initialsFrom(name) {
  const words = String(name ?? '').trim().split(/\s+/).filter(Boolean);
  if (words.length === 0) return '';
  return (words[0].charAt(0) + (words.length > 1 ? words[words.length - 1].charAt(0) : '')).toUpperCase();
}

function errorText(error, fallback) {
  const response = error?.cause?.response;
  return failureMessage(response?.data, response?.status ?? error?.status ?? 0, fallback);
}

/** Square crop with zoom: drag or use the arrow keys to move, the slider to zoom. */
function AvatarCropper({ image, onCancel, onSave, saving }) {
  const width = image.naturalWidth;
  const height = image.naturalHeight;
  const base = coverScale(width, height, FRAME);
  const [zoom, setZoom] = useState(1);
  const [offset, setOffset] = useState(() => centeredOffset(width, height, base, FRAME));
  const drag = useRef(null);
  const scale = base * zoom;

  function changeZoom(next) {
    const value = Math.min(MAX_ZOOM, Math.max(1, next));
    setOffset((current) => zoomOffset(current, scale, base * value, width, height, FRAME));
    setZoom(value);
  }

  function move(dx, dy) {
    setOffset((current) => clampOffset({ x: current.x + dx, y: current.y + dy }, width, height, scale, FRAME));
  }

  function onPointerDown(event) {
    event.currentTarget.setPointerCapture(event.pointerId);
    drag.current = { x: event.clientX, y: event.clientY };
  }

  function onPointerMove(event) {
    if (!drag.current) return;
    const dx = event.clientX - drag.current.x;
    const dy = event.clientY - drag.current.y;
    drag.current = { x: event.clientX, y: event.clientY };
    move(dx, dy);
  }

  function onKeyDown(event) {
    const moves = { ArrowLeft: [STEP, 0], ArrowRight: [-STEP, 0], ArrowUp: [0, STEP], ArrowDown: [0, -STEP] };
    if (moves[event.key]) {
      event.preventDefault();
      move(...moves[event.key]);
    } else if (event.key === '+' || event.key === '=') {
      event.preventDefault();
      changeZoom(zoom + 0.1);
    } else if (event.key === '-') {
      event.preventDefault();
      changeZoom(zoom - 0.1);
    }
  }

  function save() {
    const { sx, sy, size } = cropRect(offset, scale, FRAME);
    const canvas = document.createElement('canvas');
    canvas.width = OUTPUT;
    canvas.height = OUTPUT;
    const context = canvas.getContext('2d');
    context.fillStyle = '#FFFFFF';
    context.fillRect(0, 0, OUTPUT, OUTPUT);
    context.drawImage(image, sx, sy, size, size, 0, 0, OUTPUT, OUTPUT);
    canvas.toBlob((blob) => onSave(blob), 'image/jpeg', 0.9);
  }

  return (
    <div className="profile-cropper">
      <div
        className="profile-crop-frame"
        style={{ width: FRAME, height: FRAME }}
        tabIndex={0}
        role="group"
        aria-label="Photo position. Drag, or use the arrow keys to move it; plus and minus to zoom."
        onPointerDown={onPointerDown}
        onPointerMove={onPointerMove}
        onPointerUp={() => { drag.current = null; }}
        onPointerCancel={() => { drag.current = null; }}
        onKeyDown={onKeyDown}
      >
        <img
          src={image.src}
          alt="Preview of the new profile photo"
          draggable={false}
          style={{ width: width * scale, height: height * scale, transform: `translate(${offset.x}px, ${offset.y}px)` }}
        />
      </div>
      <p className="profile-hint">Drag the photo to choose what shows inside the square.</p>

      <label className="profile-zoom" htmlFor="profile-zoom">
        <span>Zoom</span>
        <input
          id="profile-zoom"
          type="range"
          min="1"
          max={MAX_ZOOM}
          step="0.01"
          value={zoom}
          onChange={(event) => changeZoom(Number(event.target.value))}
        />
      </label>

      <div className="profile-actions">
        <button type="button" className="program-btn primary" onClick={save} disabled={saving}>
          {saving ? 'Saving…' : 'Save photo'}
        </button>
        <button type="button" className="program-btn" onClick={onCancel} disabled={saving}>Cancel</button>
      </div>
    </div>
  );
}

/** The signed-in user's profile: photo (upload with crop, or remove), name, email and role. */
export default function Profile() {
  const { user, roleInfo, updateUser } = useAuth();
  const [image, setImage] = useState(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [saving, setSaving] = useState(false);

  // Free the preview's object URL when it is replaced or the page closes.
  useEffect(() => () => { if (image) URL.revokeObjectURL(image.src); }, [image]);

  // The success toast disappears by itself.
  useEffect(() => {
    if (!notice) return undefined;
    const timer = setTimeout(() => setNotice(''), TOAST_MS);
    return () => clearTimeout(timer);
  }, [notice]);

  const initials = initialsFrom(user?.name) || user?.avatar_initials || 'U';
  const hasPhoto = Boolean(user?.avatar_url);

  // FileField has already checked type and size (prepareAvatarFile).
  function pick(file, problem) {
    setNotice('');
    if (problem || !file) {
      setError(problem ? rejectedText(problem) : '');
      return;
    }
    if (avatarFileError(file)) {
      setError(rejectedText(avatarFileError(file)));
      return;
    }

    const preview = new Image();
    const src = URL.createObjectURL(file);
    preview.onload = () => { setError(''); setImage(preview); };
    preview.onerror = () => { URL.revokeObjectURL(src); setError(AVATAR_READ_ERROR); };
    preview.src = src;
  }

  async function save(blob) {
    if (!blob) {
      setError(AVATAR_READ_ERROR);
      return;
    }
    setSaving(true);
    setError('');
    try {
      const data = await uploadAvatar(blob);
      updateUser(data?.user);
      setImage(null);
      setNotice('Photo updated');
    } catch (err) {
      setError(errorText(err, 'Could not save the photo. Please try again.'));
    } finally {
      setSaving(false);
    }
  }

  async function remove() {
    setSaving(true);
    setError('');
    setNotice('');
    try {
      const data = await removeAvatar();
      updateUser(data?.user);
      setNotice('Photo removed');
    } catch (err) {
      setError(errorText(err, 'Could not remove the photo. Please try again.'));
    } finally {
      setSaving(false);
    }
  }

  return (
    <>
      <div className="program-page-head">
        <div>
          <div className="program-eyebrow">{roleInfo?.label ?? 'Account'}</div>
          <h1 className="program-h1">Profile</h1>
        </div>
      </div>

      {/* Always in the page so screen readers announce the toast when it appears. */}
      <div className="profile-toast-region" role="status" aria-live="polite">
        {notice && <div className="profile-toast">{notice}</div>}
      </div>

      <section className="program-card profile-card" aria-label="Your profile">
        {error && <p className="profile-alert" role="alert">{error}</p>}

        {image ? (
          <AvatarCropper image={image} saving={saving} onSave={save} onCancel={() => { setImage(null); setError(''); }} />
        ) : (
          <div className="profile-summary">
            <UserAvatar avatarUrl={user?.avatar_url} initials={initials} className="profile-avatar" />
            <div className="profile-who">
              <b>{user?.name ?? ''}</b>
              <span>{user?.email ?? ''}</span>
              {roleInfo?.label && <em className="profile-role">{roleInfo.label}</em>}
              <div className="profile-links">
                <FileField
                  id="profile-photo-file"
                  variant="link"
                  label="Change photo"
                  accept={AVATAR_ACCEPT}
                  prepare={prepareAvatarFile}
                  disabled={saving}
                  onChange={pick}
                />
                {hasPhoto && (
                  <button type="button" className="profile-link muted" onClick={remove} disabled={saving}>
                    {saving ? 'Removing…' : 'Remove'}
                  </button>
                )}
              </div>
            </div>
          </div>
        )}
      </section>
    </>
  );
}
