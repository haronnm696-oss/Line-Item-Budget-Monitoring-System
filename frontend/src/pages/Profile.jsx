import { useCallback, useEffect, useRef, useState } from 'react';
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
  anchoredOffset,
  centeredOffset,
  clampOffset,
  clampZoom,
  coverScale,
  cropRect,
  framePoint,
  wheelZoomFactor,
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

const CENTER = { x: FRAME / 2, y: FRAME / 2 };

/**
 * Square crop with zoom. Drag (one finger or the mouse) or the arrow keys move
 * the photo; the wheel, a trackpad or touch pinch, the slider and +/- zoom.
 * Wheel and pinch zoom keep the point under the cursor / pinch center still.
 */
function AvatarCropper({ image, onCancel, onSave, saving }) {
  const width = image.naturalWidth;
  const height = image.naturalHeight;
  const base = coverScale(width, height, FRAME);
  // One state object so wheel/pinch/slider/keys always start from the latest view.
  const [view, setView] = useState(() => ({ zoom: 1, offset: centeredOffset(width, height, base, FRAME) }));
  const { zoom, offset } = view;
  const frameRef = useRef(null);
  const pointers = useRef(new Map()); // pointerId -> last { x, y } (client px)
  const scale = base * zoom;

  // Zoom to `next(currentZoom)`, keeping `point` (frame px) in place.
  const zoomTo = useCallback((next, point = CENTER, pan = { x: 0, y: 0 }) => {
    setView((current) => {
      const value = clampZoom(next(current.zoom), MAX_ZOOM);
      const moved = anchoredOffset(current.offset, base * current.zoom, base * value, point);
      const nextOffset = clampOffset({ x: moved.x + pan.x, y: moved.y + pan.y }, width, height, base * value, FRAME);
      return { zoom: value, offset: nextOffset };
    });
  }, [base, width, height]);

  function move(dx, dy) {
    setView((current) => ({
      ...current,
      offset: clampOffset({ x: current.offset.x + dx, y: current.offset.y + dy }, width, height, base * current.zoom, FRAME),
    }));
  }

  // Wheel and trackpad pinch (ctrl+wheel). Native listener: React's onWheel is
  // passive, and the page must not scroll (or browser-zoom) over the frame.
  useEffect(() => {
    const frame = frameRef.current;
    if (!frame) return undefined;
    function onWheel(event) {
      event.preventDefault();
      const point = framePoint(event.clientX, event.clientY, frame.getBoundingClientRect(), FRAME);
      const factor = wheelZoomFactor(event.deltaY, event.deltaMode, event.ctrlKey);
      zoomTo((current) => current * factor, point);
    }
    // Safari's trackpad pinch sends gesture events instead of ctrl+wheel.
    let gestureScale = 1;
    function onGestureStart(event) {
      event.preventDefault();
      gestureScale = 1;
    }
    function onGestureChange(event) {
      event.preventDefault();
      const factor = event.scale / gestureScale;
      gestureScale = event.scale;
      const point = framePoint(event.clientX, event.clientY, frame.getBoundingClientRect(), FRAME);
      zoomTo((current) => current * factor, point);
    }
    frame.addEventListener('wheel', onWheel, { passive: false });
    frame.addEventListener('gesturestart', onGestureStart);
    frame.addEventListener('gesturechange', onGestureChange);
    return () => {
      frame.removeEventListener('wheel', onWheel);
      frame.removeEventListener('gesturestart', onGestureStart);
      frame.removeEventListener('gesturechange', onGestureChange);
    };
  }, [zoomTo]);

  function onPointerDown(event) {
    event.currentTarget.setPointerCapture(event.pointerId);
    pointers.current.set(event.pointerId, { x: event.clientX, y: event.clientY });
  }

  function onPointerMove(event) {
    const active = pointers.current;
    const last = active.get(event.pointerId);
    if (!last) return;
    const now = { x: event.clientX, y: event.clientY };

    if (active.size === 1) {
      active.set(event.pointerId, now);
      move(now.x - last.x, now.y - last.y);
      return;
    }

    // Two fingers: zoom by the change in distance, anchored at their midpoint,
    // and pan by how far the midpoint moved.
    const [a, b] = [...active.keys()].slice(0, 2);
    if (event.pointerId !== a && event.pointerId !== b) return;
    const before = [active.get(a), active.get(b)];
    active.set(event.pointerId, now);
    const after = [active.get(a), active.get(b)];
    const distance = (p) => Math.hypot(p[0].x - p[1].x, p[0].y - p[1].y);
    const middle = (p) => ({ x: (p[0].x + p[1].x) / 2, y: (p[0].y + p[1].y) / 2 });
    const oldDistance = distance(before);
    if (oldDistance < 1) return;
    const rect = event.currentTarget.getBoundingClientRect();
    const from = framePoint(middle(before).x, middle(before).y, rect, FRAME);
    const to = framePoint(middle(after).x, middle(after).y, rect, FRAME);
    const factor = distance(after) / oldDistance;
    zoomTo((current) => current * factor, from, { x: to.x - from.x, y: to.y - from.y });
  }

  function onPointerEnd(event) {
    pointers.current.delete(event.pointerId);
  }

  function onKeyDown(event) {
    const moves = { ArrowLeft: [STEP, 0], ArrowRight: [-STEP, 0], ArrowUp: [0, STEP], ArrowDown: [0, -STEP] };
    if (moves[event.key]) {
      event.preventDefault();
      move(...moves[event.key]);
    } else if (event.key === '+' || event.key === '=') {
      event.preventDefault();
      zoomTo((current) => current + 0.1);
    } else if (event.key === '-' || event.key === '_') {
      event.preventDefault();
      zoomTo((current) => current - 0.1);
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
        aria-label="Photo position. Drag, or use the arrow keys to move it; scroll, pinch, or plus and minus to zoom."
        ref={frameRef}
        onPointerDown={onPointerDown}
        onPointerMove={onPointerMove}
        onPointerUp={onPointerEnd}
        onPointerCancel={onPointerEnd}
        onKeyDown={onKeyDown}
      >
        <img
          src={image.src}
          alt="Preview of the new profile photo"
          draggable={false}
          style={{ width: width * scale, height: height * scale, transform: `translate(${offset.x}px, ${offset.y}px)` }}
        />
      </div>
      <p className="profile-hint">Drag to move · scroll or pinch to zoom</p>

      <label className="profile-zoom" htmlFor="profile-zoom">
        <span>Zoom</span>
        <input
          id="profile-zoom"
          type="range"
          min="1"
          max={MAX_ZOOM}
          step="0.01"
          value={zoom}
          onChange={(event) => { const value = Number(event.target.value); zoomTo(() => value); }}
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
