// Prepares one file for upload in the browser (see lib/imagePrep.js for the
// rules). Returns { file, originalSize, finalSize, note } or { error }.
// The server still checks type and the 5 MB limit.

import { MAX_FILE_MB, MAX_FILE_BYTES, fileTypeError } from './fileRules.js';
import { JPEG_QUALITY, fitWithin, jpegName, keepPng, kindOf, pickResult } from './imagePrep.js';

async function loadImage(file) {
  if (typeof createImageBitmap === 'function') {
    try {
      return await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch {
      // fall through to <img>
    }
  }
  const url = URL.createObjectURL(file);
  try {
    const img = new Image();
    img.decoding = 'async';
    img.src = url;
    await img.decode();
    return img;
  } finally {
    URL.revokeObjectURL(url);
  }
}

function sizeOf(image) {
  return { width: image.naturalWidth || image.width, height: image.naturalHeight || image.height };
}

/** True when any pixel is not fully opaque (checked on a small copy). */
function hasTransparency(image) {
  const { width, height } = sizeOf(image);
  const probe = fitWithin(width, height, 256);
  const canvas = document.createElement('canvas');
  canvas.width = probe.width;
  canvas.height = probe.height;
  const context = canvas.getContext('2d', { willReadFrequently: true });
  context.drawImage(image, 0, 0, probe.width, probe.height);
  const data = context.getImageData(0, 0, probe.width, probe.height).data;
  for (let i = 3; i < data.length; i += 4) {
    if (data[i] < 255) return true;
  }
  return false;
}

function toJpeg(image, width, height) {
  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = height;
  const context = canvas.getContext('2d');
  context.fillStyle = '#ffffff'; // transparent areas become white, not black
  context.fillRect(0, 0, width, height);
  context.imageSmoothingQuality = 'high';
  context.drawImage(image, 0, 0, width, height);
  return new Promise((resolve, reject) => {
    canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('Could not encode the image.'))), 'image/jpeg', JPEG_QUALITY);
  });
}

function tooLarge(file) {
  return `“${file.name}” is too large even after compression. The limit is ${MAX_FILE_MB} MB per file.`;
}

/** Prepare one file: compress photos, keep PDFs (and small transparent PNGs). */
export async function prepareUpload(file) {
  const typeProblem = fileTypeError(file);
  if (typeProblem) return { error: typeProblem };
  const kind = kindOf(file);
  const unchanged = (note) => (file.size <= MAX_FILE_BYTES
    ? { file, originalSize: file.size, finalSize: file.size, note }
    : { error: kind === 'pdf' ? `“${file.name}” is larger than ${MAX_FILE_MB} MB. The limit is ${MAX_FILE_MB} MB per file.` : tooLarge(file) });

  if (kind === 'pdf') return unchanged(null);

  let image;
  try {
    image = await loadImage(file);
    if (kind === 'png' && keepPng(file, hasTransparency(image))) {
      return unchanged('Kept as PNG (small, with transparency)');
    }
    const { width, height } = sizeOf(image);
    const target = fitWithin(width, height);
    const blob = await toJpeg(image, target.width, target.height);
    if (pickResult(file, blob.size, target.resized) === 'original') return unchanged('Already small; uploaded as it is');
    if (blob.size > MAX_FILE_BYTES) return { error: tooLarge(file) };
    const compressed = new File([blob], jpegName(file.name), { type: 'image/jpeg', lastModified: Date.now() });
    return {
      file: compressed,
      originalSize: file.size,
      finalSize: compressed.size,
      note: target.resized ? `Resized to ${target.width} × ${target.height} px, saved as JPEG` : 'Saved as JPEG',
    };
  } catch {
    return unchanged('Could not compress; uploading the original');
  } finally {
    if (image && typeof image.close === 'function') image.close();
  }
}
