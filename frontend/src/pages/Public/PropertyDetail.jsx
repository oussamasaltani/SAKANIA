import { useState, useEffect } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import { getProperty, getProperties } from '../../services/propertyService';
import { getImageUrl } from '../../utils/getImageUrl';
import { useAuth } from '../../contexts/AuthContext';
import PublicNavbar from '../../components/common/PublicNavbar';
import Footer from '../../components/common/Footer';
import UserAvatar from '../../components/common/UserAvatar';
import FavoriteButton from '../../components/common/FavoriteButton';
import toast from 'react-hot-toast';
import { HiLocationMarker, HiHome, HiCurrencyDollar, HiArrowsExpand, HiPhotograph, HiChatAlt } from 'react-icons/hi';
import { motion } from 'framer-motion';
import { Button } from '../../components/ui/button';
import { Badge } from '../../components/ui/badge';
import { Skeleton } from '../../components/ui/skeleton';

const PropertyDetail = () => {
  const { id } = useParams();
  const navigate = useNavigate();
  const { user } = useAuth();
  const [property, setProperty] = useState(null);
  const [similar, setSimilar] = useState([]);
  const [loading, setLoading] = useState(true);
  const [activeImage, setActiveImage] = useState(0);

  useEffect(() => {
    setLoading(true);
    getProperty(id)
      .then((res) => {
        const data = res.data?.data || res.data;
        setProperty(data);
        setActiveImage(0);
        if (data?.location) {
          getProperties({ city: data.location, per_page: 4 })
            .then((r) => {
              const all = r.data?.data || r.data || [];
              setSimilar(
                all
                  .filter((p) => p.id !== data.id && p.status === 'approved')
                  .slice(0, 3)
              );
            })
            .catch(() => {});
        }
      })
      .catch(() => {
        toast.error('Property not found');
        navigate('/properties');
      })
      .finally(() => setLoading(false));
  }, [id, navigate]);

  const handleReserve = () => {
    if (!user) {
      toast.error('Please login to reserve this property');
      navigate('/login');
      return;
    }
    if (user.role !== 'tenant') {
      toast.error('Only tenants can reserve properties');
      return;
    }
    navigate(`/tenant/reservations?property_id=${property.id}`);
  };

  const handleMessageOwner = () => {
    if (!user) {
      toast.error('Please login to message the owner');
      navigate('/login');
      return;
    }
    if (user.role !== 'tenant') {
      toast.error('Only tenants can start a conversation with an owner');
      return;
    }
    navigate(`/tenant/messages?property=${property.id}`);
  };

  if (loading) {
    return (
      <motion.div
        initial={{ opacity: 0, y: 14 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.45, ease: [0.16, 1, 0.3, 1] }}
        className="min-h-screen bg-gray-50"
      >
        <PublicNavbar />
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div className="lg:col-span-2 space-y-4">
              <Skeleton className="h-96 rounded-2xl animate-shimmer" />
              <div className="flex gap-2">
                {[...Array(4)].map((_, i) => (
                  <Skeleton key={i} className="h-20 w-24 rounded-lg" />
                ))}
              </div>
            </div>
            <div className="space-y-4">
              <Skeleton className="h-8 w-2/3" />
              <Skeleton className="h-6 w-1/3" />
              <Skeleton className="h-40 rounded-xl" />
            </div>
          </div>
        </div>
      </motion.div>
    );
  }

  if (!property) return null;

  const images = property.images?.length ? property.images : [];

  return (
    <motion.div
      initial={{ opacity: 0, y: 14 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.45, ease: [0.16, 1, 0.3, 1] }}
      className="min-h-screen bg-gray-50"
    >
      <PublicNavbar />

      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        {/* Breadcrumb */}
        <nav className="flex items-center gap-2 text-sm text-gray-500 mb-6">
          <Link to="/" className="hover:text-brand transition-colors">Home</Link>
          <span className="text-gray-300">/</span>
          <Link to="/properties" className="hover:text-brand transition-colors">Properties</Link>
          <span className="text-gray-300">/</span>
          <span className="text-gray-900 font-medium truncate">{property.title}</span>
        </nav>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
          {/* Left: Images + Details */}
          <div className="lg:col-span-2 space-y-8">
            {/* Image Gallery */}
            <div>
              <div className="h-80 md:h-96 bg-brand-soft rounded-2xl border border-gray-200/70 shadow-card overflow-hidden flex items-center justify-center">
                {images.length > 0 ? (
                  <img src={getImageUrl(images[activeImage])} alt={property.title} className="w-full h-full object-cover" />
                ) : (
                  <HiHome className="w-24 h-24 text-brand/20" />
                )}
              </div>
              {images.length > 1 && (
                <div className="flex gap-2 mt-3 overflow-x-auto pb-2">
                  {images.map((img, i) => (
                    <button
                      key={i}
                      onClick={() => setActiveImage(i)}
                      className={`shrink-0 w-20 h-16 rounded-xl overflow-hidden border-2 transition-all ${activeImage === i ? 'border-brand shadow-md shadow-brand/25' : 'border-gray-200 hover:border-brand/40'}`}
                    >
                      <img src={getImageUrl(img)} alt="" className="w-full h-full object-cover" />
                    </button>
                  ))}
                </div>
              )}
              {images.length === 0 && (
                <div className="flex items-center gap-2 mt-3 text-sm text-gray-400">
                  <HiPhotograph className="w-4 h-4" />
                  No images available
                </div>
              )}
            </div>

            {/* Property Info */}
            <div className="bg-white rounded-2xl border border-gray-200/70 shadow-card p-6">
              <div className="flex items-start justify-between">
                <div>
                  <div className="flex items-center gap-2 mb-2">
                    <Badge className="capitalize px-2.5 py-1">{property.type}</Badge>
                    {property.status === 'available' && (
                      <Badge variant="success" className="px-2.5 py-1">Available</Badge>
                    )}
                  </div>
                  <h1 className="text-2xl font-bold text-gray-900 font-display">{property.title}</h1>
                  <div className="flex items-center gap-1 text-gray-500 mt-2">
                    <HiLocationMarker className="w-4 h-4" />
                    <span>{property.location}</span>
                  </div>
                </div>
                <div className="text-right shrink-0">
                  <p className="text-3xl font-bold text-gradient-brand font-display">{property.price_per_month?.toLocaleString()}</p>
                  <p className="text-sm text-gray-500">MAD / month</p>
                </div>
              </div>

              <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-6 pt-6 border-t border-gray-100">
                {[
                  { icon: HiHome, label: 'Rooms', value: property.rooms },
                  { icon: HiHome, label: 'Bathrooms', value: property.bathrooms },
                  { icon: HiArrowsExpand, label: 'Area', value: property.area ? `${property.area} m²` : 'N/A' },
                  { icon: HiHome, label: 'Floor', value: property.floor || 'N/A' },
                ].map((item, i) => (
                  <div key={i} className="text-center p-4 bg-brand-soft/60 rounded-xl ring-1 ring-inset ring-brand/10">
                    <item.icon className="w-5 h-5 text-brand mx-auto mb-1" />
                    <p className="text-xs text-gray-500">{item.label}</p>
                    <p className="font-semibold text-gray-900 text-sm">{item.value}</p>
                  </div>
                ))}
              </div>
            </div>

            {/* Amenities */}
            {property.amenities?.length > 0 && (
              <div className="bg-white rounded-2xl border border-gray-200/70 shadow-card p-6">
                <h2 className="text-lg font-semibold text-gray-900 mb-4">Amenities</h2>
                <div className="flex flex-wrap gap-2">
                  {property.amenities.map((amenity, i) => (
                    <Badge key={i} className="px-3 py-1.5 text-sm">
                      {amenity}
                    </Badge>
                  ))}
                </div>
              </div>
            )}

            {/* Description */}
            {property.description && (
              <div className="bg-white rounded-2xl border border-gray-200/70 shadow-card p-6">
                <h2 className="text-lg font-semibold text-gray-900 mb-4">Description</h2>
                <p className="text-gray-600 leading-relaxed whitespace-pre-line">{property.description}</p>
              </div>
            )}
          </div>

          {/* Right Sidebar */}
          <div className="space-y-6">
            {/* Reserve Button */}
            <div className="bg-white rounded-2xl border border-gray-200/70 shadow-card p-6">
              <Button
                onClick={handleReserve}
                className="w-full h-12"
              >
                <HiCurrencyDollar className="w-5 h-5" />
                Reserve This Property
              </Button>
              <p className="text-xs text-gray-500 text-center mt-3">
                {user?.role === 'tenant' ? 'You will be redirected to the reservation form' : 'Login as a tenant to reserve'}
              </p>
              <Button
                onClick={handleMessageOwner}
                variant="outline"
                className="w-full h-12 mt-3"
              >
                <HiChatAlt className="w-5 h-5" />
                Message Owner
              </Button>
              <p className="text-xs text-gray-500 text-center mt-3">
                {user?.role === 'tenant'
                  ? 'Ask the owner anything about this property'
                  : 'Login as a tenant to write to the owner'}
              </p>
              <FavoriteButton property={property} variant="button" className="mt-3" />
            </div>

            {/* Owner Info */}
            {property.owner && (
              <div className="bg-white rounded-2xl border border-gray-200/70 shadow-card p-6">
                <h3 className="text-sm font-semibold text-gray-900 mb-4">Property Owner</h3>
                <div className="flex items-center gap-3">
                  <div className="w-12 h-12 bg-brand-gradient rounded-full flex items-center justify-center shrink-0 overflow-hidden shadow-lg shadow-brand/25">
                    <UserAvatar user={property.owner} className="w-full h-full text-white text-lg" />
                  </div>
                  <div>
                    {/* The users table has no `name` column, only full_name. */}
                    <p className="font-medium text-gray-900">{property.owner.full_name || property.owner.name}</p>
                    <p className="text-sm text-gray-500">Verified Owner</p>
                  </div>
                </div>
              </div>
            )}

            {/* Property Summary */}
            <div className="bg-white rounded-2xl border border-gray-200/70 shadow-card p-6">
              <h3 className="text-sm font-semibold text-gray-900 mb-4">Property Summary</h3>
              <div className="space-y-3">
                {[
                  { label: 'Type', value: property.type?.charAt(0).toUpperCase() + property.type?.slice(1) },
                  { label: 'City', value: property.city },
                   { label: 'Price', value: `${property.price_per_month?.toLocaleString()} MAD/mo` },
                  { label: 'Rooms', value: property.rooms },
                  { label: 'Bathrooms', value: property.bathrooms },
                  { label: 'Area', value: property.area ? `${property.area} m²` : 'N/A' },
                  { label: 'Capacity', value: property.capacity ? `${property.capacity} persons` : 'N/A' },
                ].filter((item) => item.value && item.value !== 'N/A').map((item, i) => (
                  <div key={i} className="flex items-center justify-between text-sm">
                    <span className="text-gray-500">{item.label}</span>
                    <span className="font-medium text-gray-900">{item.value}</span>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>

        {/* Similar Properties */}
        {similar.length > 0 && (
          <div className="mt-16">
            <h2 className="text-2xl font-bold text-gray-900 font-display mb-6">Similar Properties</h2>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
              {similar.map((p, i) => (
                <motion.div
                  key={p.id}
                  initial={{ opacity: 0, y: 16 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: i * 0.06, duration: 0.4, ease: [0.16, 1, 0.3, 1] }}
                  className="h-full"
                >
                  <Link to={`/properties/${p.id}`} className="group bg-white rounded-2xl border border-gray-200/70 shadow-card overflow-hidden hover-lift block h-full">
                    <div className="h-48 bg-brand-soft flex items-center justify-center overflow-hidden">
                      {p.images?.[0] ? (
                        <img src={getImageUrl(p.images[0])} alt={p.title} className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" />
                      ) : (
                        <HiHome className="w-14 h-14 text-brand/30" />
                      )}
                    </div>
                    <div className="p-4">
                      <Badge className="capitalize">{p.type}</Badge>
                      <h3 className="font-semibold text-gray-900 mt-2 group-hover:text-brand transition-colors truncate">{p.title}</h3>
                      <div className="flex items-center gap-1 text-gray-500 text-sm mt-1">
                        <HiLocationMarker className="w-3.5 h-3.5" />
                        <span className="truncate">{p.location}</span>
                      </div>  
                      <div className="flex items-center justify-between mt-3 pt-3 border-t border-gray-100">
                        <span className="font-bold text-brand">{p.price_per_month?.toLocaleString()} MAD<span className="text-xs font-normal text-gray-500">/mo</span></span>
                        <span className="text-xs text-gray-500">{p.rooms}R / {p.bathrooms}B</span>
                      </div>
                    </div>
                  </Link>
                </motion.div>
              ))}
            </div>
          </div>
        )}
      </div>

      <Footer />
    </motion.div>
  );
};

export default PropertyDetail;