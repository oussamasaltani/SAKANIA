import { createContext, useContext, useState, useEffect, useCallback, useMemo } from 'react';
import { useAuth } from './AuthContext';
import { getFavorites, addFavorite, removeFavorite } from '../services/favoriteService';

const FavoritesContext = createContext(null);

export const useFavorites = () => {
  const context = useContext(FavoritesContext);
  if (!context) {
    throw new Error('useFavorites must be used within a FavoritesProvider');
  }
  return context;
};

// A favorite row is { id, property_id, property }. Anywhere the property id may
// be read it is taken from the row first and its embedded property second, so
// the same helpers work for both server rows and optimistic placeholders.
const propertyIdOf = (favorite) => favorite?.property_id ?? favorite?.property?.id ?? null;

/**
 * Owns the signed-in tenant's favorites for the whole app.
 *
 * The heart on a listing and the Favorites page must agree the moment either
 * one changes, and neither should have to refetch the list to see it. This holds
 * the single copy, exposes an optimistic toggle and rolls it back if the API
 * refuses, so the UI is correct whether the request succeeds or fails.
 */
export const FavoritesProvider = ({ children }) => {
  const { user } = useAuth();
  const isTenant = user?.role === 'tenant';

  const [favorites, setFavorites] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [pendingIds, setPendingIds] = useState([]);

  const load = useCallback(async () => {
    if (!isTenant) {
      setFavorites([]);
      setError(null);
      setLoading(false);
      return;
    }

    setLoading(true);
    setError(null);
    try {
      const response = await getFavorites();
      const rows = response.data?.data ?? response.data ?? [];
      setFavorites(Array.isArray(rows) ? rows : []);
    } catch (err) {
      setError(err.response?.data?.message || 'Unable to load your favorites.');
    } finally {
      setLoading(false);
    }
  }, [isTenant]);

  useEffect(() => {
    load();
  }, [load, user?.id]);

  const favoriteIds = useMemo(
    () => favorites.map(propertyIdOf).filter((id) => id != null),
    [favorites]
  );

  const isFavorite = useCallback(
    (propertyId) => favoriteIds.includes(propertyId),
    [favoriteIds]
  );

  const toggleFavorite = useCallback(
    async (property) => {
      const propertyId = typeof property === 'object' ? property?.id : property;
      if (propertyId == null) return { ok: false };
      if (!isTenant) return { ok: false, reason: 'role' };
      if (pendingIds.includes(propertyId)) return { ok: false, reason: 'pending' };

      const wasFavorite = favoriteIds.includes(propertyId);
      const snapshot = favorites;

      setPendingIds((ids) => [...ids, propertyId]);
      setError(null);

      if (wasFavorite) {
        setFavorites((rows) => rows.filter((row) => propertyIdOf(row) !== propertyId));
      } else if (property && typeof property === 'object') {
        setFavorites((rows) => [
          { id: `pending-${propertyId}`, property_id: propertyId, property },
          ...rows,
        ]);
      }

      try {
        if (wasFavorite) {
          await removeFavorite(propertyId);
        } else {
          const response = await addFavorite(propertyId);
          const saved = response.data?.favorite;
          if (saved) {
            setFavorites((rows) =>
              rows.map((row) => (propertyIdOf(row) === propertyId ? saved : row))
            );
          }
        }
        return { ok: true, favorited: !wasFavorite };
      } catch (err) {
        setFavorites(snapshot);
        const message = err.response?.data?.message || 'Could not update your favorites.';
        setError(message);
        return { ok: false, message };
      } finally {
        setPendingIds((ids) => ids.filter((id) => id !== propertyId));
      }
    },
    [isTenant, favorites, favoriteIds, pendingIds]
  );

  const value = useMemo(
    () => ({
      favorites,
      favoriteIds,
      isFavorite,
      toggleFavorite,
      loading,
      error,
      pendingIds,
      reload: load,
    }),
    [favorites, favoriteIds, isFavorite, toggleFavorite, loading, error, pendingIds, load]
  );

  return <FavoritesContext.Provider value={value}>{children}</FavoritesContext.Provider>;
};

export default FavoritesContext;
