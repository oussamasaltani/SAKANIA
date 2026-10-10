import { useState, useEffect } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { getProperties } from '../../services/propertyService';
import { getImageUrl } from '../../utils/getImageUrl';
import PublicNavbar from '../../components/common/PublicNavbar';
import Footer from '../../components/common/Footer';
import { HiSearch, HiLocationMarker, HiHome, HiChevronLeft, HiChevronRight } from 'react-icons/hi';
import { motion } from 'framer-motion';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Skeleton } from '../../components/ui/skeleton';
import { Badge } from '../../components/ui/badge';
import FavoriteButton from '../../components/common/FavoriteButton';

const cities = ['All Cities', 'Casablanca', 'Marrakech', 'Rabat', 'Tangier', 'Fez', 'Agadir', 'Meknes', 'Oujda'];
const types = ['All Types', 'apartment', 'house', 'studio', 'villa', 'duplex'];
const roomOptions = ['Any', '1', '2', '3', '4', '5+'];

const PropertyList = () => {
  const [searchParams, setSearchParams] = useSearchParams();
  const [properties, setProperties] = useState([]);
  const [loading, setLoading] = useState(true);
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1 });

  const [filters, setFilters] = useState({
    city: searchParams.get('city') || '',
    type: searchParams.get('type') || '',
    rooms: searchParams.get('rooms') || '',
    min_price: searchParams.get('min_price') || '',
    max_price: searchParams.get('max_price') || '',
    search: searchParams.get('search') || '',
  });

  const fetchProperties = async (page = 1) => {
    setLoading(true);
    try {
      const params = { page, per_page: 9 };
      if (filters.city) params.city = filters.city;
      if (filters.type) params.type = filters.type;
      if (filters.rooms) params.rooms = filters.rooms;
      if (filters.min_price) params.min_price = filters.min_price;
      if (filters.max_price) params.max_price = filters.max_price;
      if (filters.search) params.search = filters.search;

      const res = await getProperties(params);
      const data = res.data;
      setProperties(data?.data || data || []);
      setPagination({
        current_page: data?.current_page || 1,
        last_page: data?.last_page || 1,
      });
    } catch {
      setProperties([]);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchProperties(1);
  }, []);

  const handleFilterChange = (key, value) => {
    setFilters((prev) => ({ ...prev, [key]: value }));
  };

  const applyFilters = () => {
    const params = {};
    Object.entries(filters).forEach(([k, v]) => {
      if (v) params[k] = v;
    });
    setSearchParams(params);
    fetchProperties(1);
  };

  const clearFilters = () => {
    setFilters({ city: '', type: '', rooms: '', min_price: '', max_price: '', search: '' });
    setSearchParams({});
    fetchProperties(1);
  };

  return (
    <motion.div
      initial={{ opacity: 0, y: 14 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.45, ease: [0.16, 1, 0.3, 1] }}
      className="min-h-screen bg-gray-50"
    >
      <PublicNavbar />

      <div className="relative overflow-hidden bg-white border-b border-gray-200/70">
        <div className="absolute -top-24 right-10 w-72 h-72 bg-brand/10 rounded-full blur-3xl"></div>
        <div className="absolute -bottom-24 left-1/4 w-64 h-64 bg-brand/5 rounded-full blur-3xl"></div>
        <div className="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
          <h1 className="text-3xl font-bold text-gray-900 font-display">Browse Properties</h1>
          <p className="text-sm text-gray-500 mt-2">Find your next home from our verified listings</p>
        </div>
      </div>

      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        {/* Filters */}
        <div className="bg-white rounded-2xl shadow-card border border-gray-200/70 p-6 mb-8">
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-4">
            <div className="lg:col-span-2">
              <div className="relative">
                <HiSearch className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" />
                <Input
                  type="text"
                  value={filters.search}
                  onChange={(e) => handleFilterChange('search', e.target.value)}
                  placeholder="Search properties..."
                  className="pl-10"
                />
              </div>
            </div>

            <div>
              <select
                value={filters.city}
                onChange={(e) => handleFilterChange('city', e.target.value)}
                className="w-full h-11 px-3.5 py-2.5 border border-gray-200 bg-white rounded-xl text-sm text-gray-900 shadow-sm transition-all focus:border-brand/50 focus:outline-none focus:ring-4 focus:ring-brand/10"
              >
                {cities.map((c) => (
                  <option key={c} value={c === 'All Cities' ? '' : c}>{c}</option>
                ))}
              </select>
            </div>

            <div>
              <select
                value={filters.type}
                onChange={(e) => handleFilterChange('type', e.target.value)}
                className="w-full h-11 px-3.5 py-2.5 border border-gray-200 bg-white rounded-xl text-sm text-gray-900 shadow-sm transition-all focus:border-brand/50 focus:outline-none focus:ring-4 focus:ring-brand/10 capitalize"
              >
                {types.map((t) => (
                  <option key={t} value={t === 'All Types' ? '' : t}>{t === 'All Types' ? t : t.charAt(0).toUpperCase() + t.slice(1)}</option>
                ))}
              </select>
            </div>

            <div>
              <select
                value={filters.rooms}
                onChange={(e) => handleFilterChange('rooms', e.target.value)}
                className="w-full h-11 px-3.5 py-2.5 border border-gray-200 bg-white rounded-xl text-sm text-gray-900 shadow-sm transition-all focus:border-brand/50 focus:outline-none focus:ring-4 focus:ring-brand/10"
              >
                <option value="">Rooms: Any</option>
                {roomOptions.filter((r) => r !== 'Any').map((r) => (
                  <option key={r} value={r}>{r} {r === '5+' ? 'rooms' : 'room'}</option>
                ))}
              </select>
            </div>

            <div className="flex gap-2">
              <Button onClick={applyFilters} className="flex-1">
                Filter
              </Button>
              <Button onClick={clearFilters} variant="outline" className="px-4">
                Clear
              </Button>
            </div>
          </div>

          <div className="flex gap-3 mt-4">
            <div className="flex items-center gap-2">
              <span className="text-xs text-gray-500">Budget:</span>
              <Input
                type="number"
                value={filters.min_price}
                onChange={(e) => handleFilterChange('min_price', e.target.value)}
                placeholder="Min"
                className="w-24 h-9 px-3 py-1.5 text-xs"
              />
              <span className="text-gray-400">-</span>
              <Input
                type="number"
                value={filters.max_price}
                onChange={(e) => handleFilterChange('max_price', e.target.value)}
                placeholder="Max"
                className="w-24 h-9 px-3 py-1.5 text-xs"
              />
              <span className="text-xs text-gray-400">MAD/mo</span>
            </div>
          </div>
        </div>

        {/* Results */}
        {loading ? (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            {[...Array(6)].map((_, i) => (
              <div key={i} className="bg-white rounded-2xl border border-gray-200/70 shadow-card overflow-hidden">
                <Skeleton className="h-52 rounded-none animate-shimmer" />
                <div className="p-5 space-y-3">
                  <Skeleton className="h-4 w-1/3" />
                  <Skeleton className="h-5 w-2/3" />
                  <Skeleton className="h-3 w-1/2" />
                  <Skeleton className="h-3 w-1/4" />
                </div>
              </div>
            ))}
          </div>
        ) : properties.length === 0 ? (
          <div className="text-center py-20">
            <div className="w-12 h-12 rounded-2xl bg-gray-100 flex items-center justify-center mx-auto mb-3">
              <HiHome className="w-6 h-6 text-gray-400" />
            </div>
            <h3 className="text-sm font-medium text-gray-600">No properties found</h3>
            <p className="text-xs text-gray-400 mt-1">Try adjusting your filters or search terms</p>
            <Button onClick={clearFilters} size="sm" className="mt-4">
              Clear Filters
            </Button>
          </div>
        ) : (
          <>
            <p className="text-sm text-gray-500 mb-4">{properties.length} properties found</p>
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
              {properties.map((property, i) => (
                <motion.div
                  key={property.id}
                  initial={{ opacity: 0, y: 16 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: i * 0.06, duration: 0.4, ease: [0.16, 1, 0.3, 1] }}
                  className="relative h-full"
                >
                  <FavoriteButton
                    property={property}
                    className="absolute top-3 right-3 z-10"
                  />
                  <Link to={`/properties/${property.id}`} className="group bg-white rounded-2xl border border-gray-200/70 shadow-card overflow-hidden hover-lift block h-full">
                    <div className="h-52 bg-brand-soft flex items-center justify-center overflow-hidden">
                      {property.images?.[0] ? (
                        <img src={getImageUrl(property.images[0])} alt={property.title} className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" />
                      ) : (
                        <HiHome className="w-16 h-16 text-brand/30" />
                      )}
                    </div>
                    <div className="p-5">
                      <div className="flex items-center gap-2 mb-2">
                        <Badge className="capitalize">{property.type}</Badge>
                        {property.status === 'available' && (
                          <Badge variant="success">Available</Badge>
                        )}
                      </div>
                      <h3 className="font-semibold text-gray-900 group-hover:text-brand transition-colors truncate">{property.title}</h3>
                      <div className="flex items-center gap-1 text-gray-500 text-sm mt-1">
                        <HiLocationMarker className="w-4 h-4 shrink-0" />
                        <span className="truncate">{property.location}</span>
                      </div>
                      <div className="flex items-center gap-4 mt-2 text-sm text-gray-500">
                        <span className="flex items-center gap-1"><HiHome className="w-4 h-4" />{property.rooms} rooms</span>
                        <span>{property.bathrooms} baths</span>
                        {property.area && <span>{property.area} m²</span>}
                      </div>
                      <div className="flex items-center justify-between mt-4 pt-4 border-t border-gray-100">
                        <span className="text-lg font-bold text-brand">{property.price_per_month?.toLocaleString()} MAD<span className="text-sm font-normal text-gray-500">/mo</span></span>
                        <span className="text-sm text-brand font-medium group-hover:underline">View Details</span>
                      </div>
                    </div>
                  </Link>
                </motion.div>
              ))}
            </div>

            {pagination.last_page > 1 && (
              <div className="flex items-center justify-center gap-2 mt-10">
                <Button
                  variant="outline"
                  size="icon"
                  onClick={() => fetchProperties(pagination.current_page - 1)}
                  disabled={pagination.current_page === 1}
                >
                  <HiChevronLeft className="w-5 h-5" />
                </Button>
                {[...Array(pagination.last_page)].map((_, i) => (
                  <button
                    key={i}
                    onClick={() => fetchProperties(i + 1)}
                    className={`w-10 h-10 rounded-xl text-sm font-medium transition-all ${
                      pagination.current_page === i + 1
                        ? 'bg-brand-gradient text-white shadow-lg shadow-brand/25'
                        : 'border border-gray-200 bg-white text-gray-600 hover:border-brand/40 hover:text-brand hover:bg-brand-soft'
                    }`}
                  >
                    {i + 1}
                  </button>
                ))}
                <Button
                  variant="outline"
                  size="icon"
                  onClick={() => fetchProperties(pagination.current_page + 1)}
                  disabled={pagination.current_page === pagination.last_page}
                >
                  <HiChevronRight className="w-5 h-5" />
                </Button>
              </div>
            )}
          </>
        )}
      </div>

      <Footer />
    </motion.div>
  );
};

export default PropertyList;