import { useNavigate } from 'react-router-dom';
import { HiHeart, HiOutlineHeart } from 'react-icons/hi';
import toast from 'react-hot-toast';
import { useAuth } from '../../contexts/AuthContext';
import { useFavorites } from '../../contexts/FavoritesContext';
import { Button } from '../ui/button';

/**
 * Heart toggle for a single property.
 *
 * `variant="icon"` is the compact dot used on listing cards;
 * `variant="button"` is the labelled pill used on the property page. Guests are
 * sent to login, and favorites stay with the tenant workspace, so other signed-in
 * roles never see it.
 */
const FavoriteButton = ({ property, variant = 'icon', className = '', label }) => {
  const { user } = useAuth();
  const { isFavorite, toggleFavorite, loading, pendingIds } = useFavorites();
  const navigate = useNavigate();

  const propertyId = typeof property === 'object' ? property?.id : property;
  if (propertyId == null) return null;
  if (user && user.role !== 'tenant') return null;

  const favorited = isFavorite(propertyId);
  const pending = pendingIds.includes(propertyId);
  const disabled = pending || (!!user && loading);

  const handleClick = async (event) => {
    event.preventDefault();
    event.stopPropagation();

    if (!user) {
      toast.error('Please login to save properties to your favorites');
      navigate('/login');
      return;
    }

    const result = await toggleFavorite(property);
    if (result.ok) {
      toast.success(result.favorited ? 'Added to favorites' : 'Removed from favorites');
    } else if (result.message) {
      toast.error(result.message);
    }
  };

  const title = favorited ? 'Remove from favorites' : 'Add to favorites';

  if (variant === 'button') {
    return (
      <Button
        type="button"
        variant="outline"
        onClick={handleClick}
        disabled={disabled}
        aria-pressed={favorited}
        aria-label={title}
        title={title}
        className={`w-full h-12 ${favorited ? 'text-rose-600 border-rose-200 hover:bg-rose-50' : ''} ${className}`}
      >
        {favorited ? <HiHeart className="w-5 h-5 text-rose-500" /> : <HiOutlineHeart className="w-5 h-5" />}
        {label ?? (favorited ? 'Saved to Favorites' : 'Save to Favorites')}
      </Button>
    );
  }

  return (
    <button
      type="button"
      onClick={handleClick}
      disabled={disabled}
      aria-pressed={favorited}
      aria-label={title}
      title={title}
      className={`inline-flex items-center justify-center w-10 h-10 rounded-full border shadow-sm backdrop-blur-md transition-all duration-200 active:scale-95 disabled:opacity-60 disabled:cursor-not-allowed ${
        favorited
          ? 'bg-rose-50/95 border-rose-200 text-rose-500 hover:bg-rose-100'
          : 'bg-white/90 border-gray-200 text-gray-500 hover:text-rose-500 hover:border-rose-200'
      } ${pending ? 'animate-pulse' : ''} ${className}`}
    >
      {favorited ? <HiHeart className="w-5 h-5" /> : <HiOutlineHeart className="w-5 h-5" />}
    </button>
  );
};

export default FavoriteButton;
