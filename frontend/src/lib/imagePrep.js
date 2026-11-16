// Pure rules for preparing an upload in the browser (no DOM here, so they
// can be unit-tested). lib/compressImage.js does the canvas work.
//
//   PDF            uploaded as it is
//   JPG / PNG      resized so the longest side is at most 1600 px and saved
//                  as JPEG at quality 0.8
//   PNG exception  kept as PNG when it is small (<= 1 MB) and has
//                  transparency (a logo or a signature, not a photo)
//   If compressing fails, the original is uploaded when it is within 5 MB.

import { MAX_FILE_BYTES } from './fileRules.js';

export const MAX_SIDE = 1600;
export const JPEG_QUALITY = 0.8;
export const SMALL_PNG_BYTES = 1024 * 1024;

export function kindOf(file) {
  const extension = String(file?.name ?? '').split('.').pop().toLowerCase();
  const type = String(file?.type ?? '').toLowerCase();
  if (extension === 'pdf' || type === 'application/pdf') return 'pdf';
  if (extension === 'png' || type === 'image/png') return 'png';
  if (['jpg', 'jpeg'].includes(extension) || type === 'image/jpeg') return 'jpeg';
  return 'other';
}

/** Target size that keeps the aspect ratio with the longest side <= max. */
export function fitWithin(width, height, max = MAX_SIDE) {
  const longest = Math.max(width, height);
  if (!(longest > max)) return { width, height, resized: false };
  const scale = max / longest;
  return { width: Math.max(1, Math.round(width * scale)), height: Math.max(1, Math.round(height * scale)), resized: true };
}

/** "Receipt scan.PNG" -> "Receipt scan.jpg" */
export function jpegName(name) {
  const base = String(name ?? 'image').replace(/\.[^.]+$/, '');
  return `${base || 'image'}.jpg`;
}

/** Keep a PNG as it is only when it is small and has transparency. */
export function keepPng(file, hasTransparency) {
  return Boolean(hasTransparency) && Number(file?.size) <= SMALL_PNG_BYTES;
}

/**
 * Choose between the compressed JPEG and the original. The JPEG wins unless
 * it is larger than an original that needed no resizing and already fits.
 */
export function pickResult(original, compressedSize, resized) {
  const originalFits = Number(original.size) <= MAX_FILE_BYTES;
  if (!resized && originalFits && compressedSize >= Number(original.size)) return 'original';
  return 'compressed';
}

/** "2.4 MB", "310 KB" */
export function formatBytes(bytes) {
  const n = Number(bytes) || 0;
  if (n >= 1024 * 1024) return `${(n / (1024 * 1024)).toFixed(1)} MB`;
  return `${Math.max(1, Math.round(n / 1024))} KB`;
}
