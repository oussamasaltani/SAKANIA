import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import {
  HiHeart,
  HiLocationMarker,
  HiHome,
  HiRefresh,
  HiArrowRight,
} from 'react-icons/hi';
import { useFavorites } from '../../contexts/FavoritesContext';
import { getImageUrl } from '../../utils/getImageUrl';
import FavoriteButton from '../../components/common/FavoriteButton';
import { Button } from '../../components/ui/button';
import { Badge } from '../../components/ui/badge';
import { Skeleton } from '../../components/ui/skeleton';

const containerVariants = {
  hidden: { opacity: 0 },
  visible: {
    opacity: 1,
    transition: { staggerChildren: 0.06, delayChildren: 0.05 },
  },
};

const cardVariants = {
  hidden: { opacity: 0, y: 20, scale: 0.98 },
  visible: {
    opacity: 1,
    y: 0,
    scale: 1,
    transition: { type: 'spring', stiffness: 260, damping: 20 },
  },
};

const FavoritesSkeleton = () => (
  <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-6">
    {[...Array(6)].map((_, i) => (
      <div key={i} className="rounded-2xl border border-slate-200/80 bg-white overflow-hidden shadow-xs">
        <Skeleton className="h-48 rounded-none" />
        <div className="p-5 space-y-3">
          <Skeleton className="h-4 w-1/3 rounded-md" />
          <Skeleton className="h-5 w-2/3 rounded-md" />
          <Skeleton className="h-3 w-1/2 rounded-md" />
          <Skeleton className="h-9 w-full rounded-xl" />
        </div>
      </div>
    ))}
  </div>
);

const Favorites = () => {
  const { favorites, loading, error, reload } = useFavorites();

  const showSkeleton = loading && favorites.length === 0;
  const showError = !loading && !!error && favorites.length === 0;
  const showEmpty = !loading && !error && favorites.length === 0;

  return (
    <motion.div
      initial={{ opacity: 0, y: 12 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.35, ease: 'easeOut' }}
      className="p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto space-y-6 antialiased selection:bg-orange-500 selection:text-white"
    >
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-2 border-b border-slate-200/60">
        <div className="flex items-center gap-3">
          <div className="h-7 w-1.5 rounded-full bg-gradient-to-b from-orange-500 to-amber-500 shadow-sm" />
          <div>
            <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900 font-display">
              My Favorites
            </h1>
            <p className="text-sm text-slate-500 mt-1.5 font-normal leading-relaxed">
              Properties you have saved for later.
            </p>
          </div>
        </div>

        {!showSkeleton && !showError && favorites.length > 0 && (
          <span className="inline-flex items-center gap-1.5 self-start sm:self-auto px-3 py-1.5 rounded-full text-xs font-semibold bg-orange-50 border border-orange-200/80 text-orange-700 shadow-2xs">
            <HiHeart className="w-3.5 h-3.5" />
            {favorites.length} {favorites.length === 1 ? 'property' : 'properties'}
          </span>
        )}
      </div>

      {showSkeleton ? (
        <FavoritesSkeleton />
      ) : showError ? (
        <motion.div
          initial={{ opacity: 0, scale: 0.98 }}
          animate={{ opacity: 1, scale: 1 }}
          transition={{ duration: 0.25 }}
          className="p-10 text-center rounded-2xl border border-slate-200/80 bg-white shadow-xs"
        >
          <div className="w-16 h-16 bg-rose-50 rounded-2xl flex items-center justify-center mx-auto mb-4 border border-rose-100 text-rose-600 shadow-xs">
            <HiRefresh className="w-8 h-8" />
          </div>
          <h3 className="text-lg font-bold text-slate-900 tracking-tight">Could not load favorites</h3>
          <p className="text-slate-500 text-sm mt-1.5 max-w-sm mx-auto leading-relaxed">{error}</p>
          <Button onClick={reload} className="mt-5">
            <HiRefresh className="w-4 h-4" />
            Try again
          </Button>
        </motion.div>
      ) : showEmpty ? (
        <motion.div
          initial={{ opacity: 0, scale: 0.98 }}
          animate={{ opacity: 1, scale: 1 }}
          transition={{ duration: 0.25 }}
          className="p-10 sm:p-14 text-center rounded-2xl border border-slate-200/80 bg-white shadow-xs"
        >
          <div className="w-16 h-16 bg-orange-50 rounded-2xl flex items-center justify-center mx-auto mb-4 border border-orange-100 text-orange-600 shadow-xs">
            <HiHeart className="w-8 h-8" />
          </div>
          <h3 className="text-lg font-bold text-slate-900 tracking-tight">No favorites yet</h3>
          <p className="text-slate-500 text-sm mt-1.5 max-w-sm mx-auto leading-relaxed">
            Tap the heart on any property to save it here and find it again quickly.
          </p>
          <Link to="/properties" className="inline-block mt-5">
            <Button>
              Browse Properties
              <HiArrowRight className="w-4 h-4" />
            </Button>
          </Link>
        </motion.div>
      ) : (
        <motion.div
          variants={containerVariants}
          initial="hidden"
          animate="visible"
          className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-6"
        >
          {favorites.map((favorite) => {
            const property = favorite.property;
            if (!property) return null;
            return (
              <motion.div key={favorite.id} variants={cardVariants} whileHover={{ y: -4 }} className="h-full">
                <div className="group flex flex-col h-full rounded-2xl border border-slate-200/80 bg-white overflow-hidden shadow-xs hover:shadow-xl hover:shadow-slate-200/50 hover:border-slate-300 transition-all duration-200">
                  <div className="relative h-48 bg-slate-50 border-b border-slate-200/80 overflow-hidden">
                    <Link to={`/properties/${property.id}`} className="block w-full h-full">
                      {property.images?.[0] ? (
                        <img
                          src={getImageUrl(property.images[0])}
                          alt={property.title}
                          className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500 ease-out"
                        />
                      ) : (
                        <div className="w-full h-full flex items-center justify-center">
                          <HiHome className="w-14 h-14 text-slate-300 stroke-[1.5]" />
                        </div>
                      )}
                    </Link>
                    <div className="absolute top-3 right-3">
                      <FavoriteButton property={property} />
                    </div>
                  </div>

                  <div className="p-5 flex-1 flex flex-col">
                    <div className="flex items-center gap-2 mb-2">
                      <Badge className="capitalize">{property.type}</Badge>
                    </div>
                    <Link to={`/properties/${property.id}`} className="no-underline">
                      <h3 className="font-bold text-slate-900 group-hover:text-orange-600 transition-colors duration-200 truncate tracking-tight">
                        {property.title}
                      </h3>
                    </Link>
                    <div className="flex items-center gap-1 text-slate-500 text-sm mt-1">
                      <HiLocationMarker className="w-4 h-4 shrink-0" />
                      <span className="truncate">{property.location}</span>
                    </div>
                    <div className="flex items-center gap-4 mt-2 text-sm text-slate-500">
                      {property.rooms != null && <span>{property.rooms} rooms</span>}
                      {property.bathrooms != null && <span>{property.bathrooms} baths</span>}
                      {property.area != null && <span>{property.area} m²</span>}
                    </div>

                    <div className="flex items-center justify-between mt-auto pt-4 border-t border-slate-100">
                      <span className="text-lg font-extrabold text-slate-900 tracking-tight">
                        {property.price_per_month?.toLocaleString()}
                        <span className="text-sm font-bold text-orange-600"> MAD</span>
                        <span className="text-xs font-normal text-slate-400">/mo</span>
                      </span>
                      <Link
                        to={`/properties/${property.id}`}
                        className="inline-flex items-center gap-1 text-sm font-semibold text-orange-700 hover:text-orange-800 no-underline"
                      >
                        View
                        <HiArrowRight className="w-4 h-4" />
                      </Link>
                    </div>
                  </div>
                </div>
              </motion.div>
            );
          })}
        </motion.div>
      )}
    </motion.div>
  );
};

export default Favorites;
