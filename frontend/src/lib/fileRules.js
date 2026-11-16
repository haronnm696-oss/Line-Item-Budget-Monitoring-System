// Upload rules shown and checked in every upload control. The server checks
// the same rules (config/documents.php); this only gives an immediate,
// clear message before anything is sent.

export const MAX_FILE_MB = 5;
export const MAX_FILE_BYTES = MAX_FILE_MB * 1024 * 1024;
export const FILE_ACCEPT = '.pdf,.jpg,.jpeg,.png';
export const FILE_HINT = `PDF, JPG or PNG, up to ${MAX_FILE_MB} MB`;

const ALLOWED = ['pdf', 'jpg', 'jpeg', 'png'];

function sizeText(bytes) {
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/** Wrong file type, or null. (Photos are checked for size after compression.) */
export function fileTypeError(file) {
  if (!file) return null;
  const extension = String(file.name ?? '').split('.').pop().toLowerCase();
  return ALLOWED.includes(extension) ? null : 'Upload a PDF, JPG or PNG file.';
}

/** Why this file cannot be uploaded as it is, or null when it is fine. */
export function fileError(file) {
  if (!file) return null;
  const typeProblem = fileTypeError(file);
  if (typeProblem) return typeProblem;
  if (Number(file.size) > MAX_FILE_BYTES) {
    return `“${file.name}” is ${sizeText(file.size)}. The limit is ${MAX_FILE_MB} MB per file.`;
  }
  return null;
}
