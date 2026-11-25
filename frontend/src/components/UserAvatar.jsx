import { useState } from 'react';
import { apiUrl } from '../api/leader';
import { avatarView } from '../lib/avatar';

/**
 * The round user avatar: the profile photo when the user has one
 * (avatar_url), otherwise their initials. A photo that fails to load falls
 * back to the initials. Decorative: the name is always shown next to it.
 */
export default function UserAvatar({ avatarUrl, initials, className = '' }) {
  const view = avatarView(avatarUrl, initials, apiUrl);
  const [failedSrc, setFailedSrc] = useState(null);
  const showImage = view.kind === 'image' && failedSrc !== view.src;

  return (
    <span className={`program-avatar${showImage ? ' has-photo' : ''}${className ? ` ${className}` : ''}`} aria-hidden="true">
      {showImage
        ? <img src={view.src} alt="" onError={() => setFailedSrc(view.src)} />
        : view.initials}
    </span>
  );
}
