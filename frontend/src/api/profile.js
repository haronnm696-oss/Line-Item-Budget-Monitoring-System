import client from './client';
import { ENDPOINTS } from './endpoints';
import { performWrite } from '../lib/apiResult';

/** Upload the cropped photo (a JPEG blob). Resolves with { user }. */
export function uploadAvatar(blob) {
  const form = new FormData();
  form.append('avatar', blob, 'avatar.jpg');
  return performWrite(() => client.post(ENDPOINTS.profileAvatar, form));
}

/** Remove the photo. Resolves with { user }. */
export function removeAvatar() {
  return performWrite(() => client.delete(ENDPOINTS.profileAvatar));
}
