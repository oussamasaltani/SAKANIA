import { getImageUrl, getInitials } from '../../utils/getImageUrl';

/**
 * The user's picture, with the first letter of their name as the fallback.
 *
 * Every place that shows a person renders the same two states, and the stored
 * value needs rooting before the browser will load it (it is a path relative to
 * public/), so the decision is made once here instead of at each call site.
 */
const UserAvatar = ({ user, className = '', fallbackClassName = '', src: srcProp, name: nameProp }) => {
  const src = srcProp || getImageUrl(user);
  const name = nameProp ?? user?.full_name ?? user?.name;

  if (src) {
    return <img src={src} alt="" className={`object-cover ${className}`} />;
  }

  return (
    <span className={`font-semibold ${className} ${fallbackClassName}`}>
      {getInitials(name)}
    </span>
  );
};

export default UserAvatar;