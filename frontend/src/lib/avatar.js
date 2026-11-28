// Profile photo helpers (components/UserAvatar.jsx, pages/Profile.jsx).
// Pure functions so they can be tested with node:test.

export const AVATAR_MAX_BYTES = 2 * 1024 * 1024;
export const AVATAR_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
export const AVATAR_TYPE_ERROR = 'Choose a JPG, PNG or WebP image.';
export const AVATAR_SIZE_ERROR = 'The photo must be 2 MB or smaller.';
export const AVATAR_READ_ERROR = 'Could not read this image. Choose a JPG, PNG or WebP image.';
export const AVATAR_ACCEPT = '.jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp';
export const AVATAR_HINT = 'JPG, PNG or WebP, up to 2 MB';

const EXTENSION_TYPES = { jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp' };

/**
 * What the avatar shows: the photo when the user has one, else initials.
 * `toUrl` turns the API's relative avatar_url into a loadable URL.
 */
export function avatarView(avatarUrl, initials, toUrl = (path) => path) {
  const text = String(initials || '').trim() || 'U';
  const url = typeof avatarUrl === 'string' && avatarUrl.trim() ? avatarUrl.trim() : null;
  return url ? { kind: 'image', src: toUrl(url), initials: text } : { kind: 'initials', src: null, initials: text };
}

/** '' when the picked file may be uploaded, else the message to show. */
export function avatarFileError(file) {
  if (!file) return AVATAR_TYPE_ERROR;
  const extension = String(file.name || '').split('.').pop().toLowerCase();
  const type = file.type || EXTENSION_TYPES[extension] || '';
  if (!AVATAR_TYPES.includes(type)) return AVATAR_TYPE_ERROR;
  if (Number(file.size) > AVATAR_MAX_BYTES) return AVATAR_SIZE_ERROR;
  return '';
}

/** FileField `prepare` for the photo: check type and size; no compression. */
export async function prepareAvatarFile(file) {
  const error = avatarFileError(file);
  return error ? { error } : { file, originalSize: file.size, finalSize: file.size, note: null };
}

// Square crop. The image is drawn at `scale` (screen px per image px) with
// its top-left corner at `offset` inside a square `frame` (px). At zoom 1 the
// image just covers the frame.

export function coverScale(width, height, frame) {
  return frame / Math.min(width, height);
}

/** Keeps the image covering the whole frame (no empty edges). */
export function clampOffset(offset, width, height, scale, frame) {
  const minX = frame - width * scale;
  const minY = frame - height * scale;
  return {
    x: Math.min(0, Math.max(minX, offset.x)),
    y: Math.min(0, Math.max(minY, offset.y)),
  };
}

export function centeredOffset(width, height, scale, frame) {
  return { x: (frame - width * scale) / 2, y: (frame - height * scale) / 2 };
}

/** Zoom stays between 1 (image just covers the frame) and `max`. */
export function clampZoom(zoom, max) {
  if (!Number.isFinite(zoom)) return 1;
  return Math.min(max, Math.max(1, zoom));
}

/**
 * Offset after a scale change that keeps the image point under `point`
 * (frame px) under that same point. Not clamped.
 */
export function anchoredOffset(offset, oldScale, newScale, point) {
  const ratio = newScale / oldScale;
  return { x: point.x - (point.x - offset.x) * ratio, y: point.y - (point.y - offset.y) * ratio };
}

/** New offset after a zoom change anchored at `point` (frame px), clamped. */
export function zoomAt(offset, oldScale, newScale, point, width, height, frame) {
  return clampOffset(anchoredOffset(offset, oldScale, newScale, point), width, height, newScale, frame);
}

/** New offset after a zoom change, keeping the frame's center in place. */
export function zoomOffset(offset, oldScale, newScale, width, height, frame) {
  const center = frame / 2;
  return zoomAt(offset, oldScale, newScale, { x: center, y: center }, width, height, frame);
}

/** A screen point (clientX/Y) in frame px, for a frame drawn at `rect`. */
export function framePoint(clientX, clientY, rect, frame) {
  const sx = rect.width ? frame / rect.width : 1;
  const sy = rect.height ? frame / rect.height : 1;
  return { x: (clientX - rect.left) * sx, y: (clientY - rect.top) * sy };
}

/**
 * Zoom multiplier for one wheel event. Scrolling up/away (negative deltaY)
 * zooms in. ctrlKey marks a trackpad pinch, whose deltas are small, so it
 * zooms faster per pixel. deltaMode 1 = lines, 2 = pages.
 */
export function wheelZoomFactor(deltaY, deltaMode = 0, ctrlKey = false) {
  const pixels = deltaMode === 1 ? deltaY * 16 : deltaMode === 2 ? deltaY * 240 : deltaY;
  const step = Math.max(-120, Math.min(120, Number(pixels) || 0));
  return Math.exp(-step * (ctrlKey ? 0.01 : 0.0015));
}

/** The square of the source image (image px) that the frame shows. */
export function cropRect(offset, scale, frame) {
  return { sx: -offset.x / scale, sy: -offset.y / scale, size: frame / scale };
}
