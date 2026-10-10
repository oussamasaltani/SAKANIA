/**
 * Turns a stored image reference into something an <img src> can load.
 *
 * The API stores upload paths relative to public/ ("uploads/properties/x.jpg"),
 * which is what nginx serves, so they have to be rooted before being handed to
 * the browser. An absolute URL is already usable and left alone.
 *
 * Property photos carry `image_url`, users carry `avatar` (and the API adds a
 * ready-made `avatar_url`), so both are accepted.
 */
export const getImageUrl = (image) => {
  if (!image) return null;
  const url =
    typeof image === 'string'
      ? image
      : image.avatar_url || image.image_url || image.avatar || null;
  if (!url) return null;
  if (url.startsWith('http') || url.startsWith('data:')) return url;
  return `/${url.replace(/^\//, '')}`;
};

/** First letter of a name, for the initials shown when there is no picture. */
export const getInitials = (name) => (name || '?').trim().charAt(0).toUpperCase() || '?';
