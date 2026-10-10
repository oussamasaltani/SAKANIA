import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import { getProperties, deleteProperty } from '../../services/ownerService';
import { getImageUrl } from '../../utils/getImageUrl';
import toast from 'react-hot-toast';
import {
  HiBuildingOffice2,
  HiPlus,
  HiPencilSquare,
  HiTrash,
  HiMagnifyingGlass,
  HiXMark,
  HiMapPin,
  HiFunnel,
  HiChevronDown,
  HiSparkles
} from 'react-icons/hi2';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Skeleton } from '../../components/ui/skeleton';
import BrandLoader from '../../components/common/BrandLoader';

const statusColors = {
  approved: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  pending: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  rejected: 'bg-rose-50 text-rose-700 ring-rose-600/20',
};

const statusDots = {
  approved: 'bg-emerald-500',
  pending: 'bg-amber-500',
  rejected: 'bg-rose-500',
};

const statusLabels = {
  approved: 'Approved',
  pending: 'Pending',
  rejected: 'Rejected',
};

// Motion Variants
const containerVariants = {
  hidden: { opacity: 0 },
  visible: {
    opacity: 1,
    transition: {
      staggerChildren: 0.06,
      delayChildren: 0.05,
    },
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

const PropertiesSkeleton = () => (
  <div className="space-y-6">
    <div className="flex flex-col sm:flex-row gap-4">
      <Skeleton className="h-11 flex-1 rounded-xl" />
      <Skeleton className="h-11 w-full sm:w-44 rounded-xl" />
    </div>
    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
      {[...Array(6)].map((_, i) => (
        <div key={i} className="rounded-2xl border border-slate-200/80 bg-white overflow-hidden p-0 shadow-sm space-y-3">
          <Skeleton className="h-52 w-full rounded-none" />
          <div className="p-5 space-y-3.5">
            <Skeleton className="h-5 w-3/4 rounded-md" />
            <Skeleton className="h-4 w-1/2 rounded-md" />
            <div className="pt-2 flex justify-between items-center">
              <Skeleton className="h-6 w-1/3 rounded-md" />
              <Skeleton className="h-4 w-1/4 rounded-md" />
            </div>
            <div className="pt-3 flex gap-2">
              <Skeleton className="h-9 flex-1 rounded-xl" />
              <Skeleton className="h-9 w-10 rounded-xl" />
            </div>
          </div>
        </div>
      ))}
    </div>
  </div>
);

const ConfirmModal = ({ isOpen, title, message, onConfirm, onCancel, loading }) => {
  return (
    <AnimatePresence>
      {isOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.2 }}
            className="absolute inset-0 bg-slate-900/40 backdrop-blur-md"
            onClick={onCancel}
          />
          <motion.div
            initial={{ opacity: 0, scale: 0.92, y: 12 }}
            animate={{ opacity: 1, scale: 1, y: 0 }}
            exit={{ opacity: 0, scale: 0.95, y: 8 }}
            transition={{ type: 'spring', damping: 25, stiffness: 320 }}
            className="relative w-full max-w-md bg-white rounded-2xl border border-slate-200/80 p-6 shadow-2xl shadow-slate-900/10 z-10"
          >
            <button
              onClick={onCancel}
              className="absolute top-4 right-4 p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100 transition-colors"
            >
              <HiXMark className="w-5 h-5" />
            </button>

            <div className="w-12 h-12 rounded-2xl bg-rose-50 border border-rose-100 flex items-center justify-center mx-auto mb-4 text-rose-600">
              <HiTrash className="w-6 h-6" />
            </div>

            <h3 className="text-lg font-bold text-slate-900 text-center tracking-tight">{title}</h3>
            <p className="text-sm text-slate-500 text-center mt-2 leading-relaxed">{message}</p>

            <div className="flex gap-3 mt-6">
              <Button
                variant="outline"
                onClick={onCancel}
                disabled={loading}
                className="flex-1 h-11 rounded-xl border-slate-200 hover:bg-slate-50 font-medium text-slate-700"
              >
                Cancel
              </Button>
              <Button
                variant="destructive"
                onClick={onConfirm}
                disabled={loading}
                className="flex-1 h-11 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-medium shadow-sm transition-all"
              >
                {loading ? (
                  <div className="flex items-center justify-center gap-2">
                    <BrandLoader size="small" showText={false} />
                    <span>Deleting...</span>
                  </div>
                ) : (
                  'Delete'
                )}
              </Button>
            </div>
          </motion.div>
        </div>
      )}
    </AnimatePresence>
  );
};

const Properties = () => {
  const [properties, setProperties] = useState([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [deleteModal, setDeleteModal] = useState({ open: false, id: null, title: '' });
  const [deleting, setDeleting] = useState(false);

  const fetchProperties = () => {
    setLoading(true);
    getProperties()
      .then((res) => setProperties(res.data?.data || res.data || []))
      .catch(() => toast.error('Failed to load properties'))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    fetchProperties();
  }, []);

  const filtered = properties.filter((p) => {
    const matchesSearch =
      p.title?.toLowerCase().includes(search.toLowerCase()) ||
      p.city?.toLowerCase().includes(search.toLowerCase());
    const matchesStatus = statusFilter === 'all' || p.status === statusFilter;
    return matchesSearch && matchesStatus;
  });

  const handleDelete = async () => {
    if (!deleteModal.id) return;
    setDeleting(true);
    try {
      await deleteProperty(deleteModal.id);
      toast.success('Property deleted successfully');
      setProperties((prev) => prev.filter((p) => p.id !== deleteModal.id));
      setDeleteModal({ open: false, id: null, title: '' });
    } catch (err) {
      toast.error(err.response?.data?.message || 'Failed to delete property');
    } finally {
      setDeleting(false);
    }
  };

  return (
    <motion.div
      initial={{ opacity: 0, y: 12 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.35, ease: 'easeOut' }}
      className="p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto space-y-6 antialiased selection:bg-orange-500 selection:text-white"
    >
      {/* Header Section */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-2 border-b border-slate-200/60">
        <div className="relative">
          <div className="flex items-center gap-3">
            {/* Orange Gradient Accent Bar */}
            <div className="h-7 w-1.5 rounded-full bg-gradient-to-b from-orange-500 to-amber-500 shadow-sm" />

            <div className="flex items-center gap-3 flex-wrap">
              <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900 font-display">
                My Properties
              </h1>

              {/* Pro Badge with Live Pulse Dot */}
              <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-orange-50 border border-orange-200/80 text-orange-700 shadow-2xs">
                <span className="relative flex h-2 w-2">
                  <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-orange-400 opacity-75"></span>
                  <span className="relative inline-flex rounded-full h-2 w-2 bg-orange-500"></span>
                </span>
                {properties.length} {properties.length === 1 ? 'Property' : 'Properties'}
              </span>
            </div>
          </div>

          <p className="text-sm text-slate-500 mt-1.5 ml-4.5 font-normal leading-relaxed">
            Manage, update, and monitor your active rental listings in real time.
          </p>
        </div>
        <Button
          asChild
          className="shrink-0 h-11 px-5 bg-slate-900 hover:bg-slate-800 text-white font-semibold text-sm rounded-xl shadow-sm hover:shadow-md active:scale-[0.98] transition-all duration-200 border border-slate-800 no-underline hover:no-underline cursor-pointer"
        >
          <Link to="/owner/properties/add" className="flex items-center gap-2 no-underline text-white">
            <HiPlus className="w-5 h-5 text-orange-500 stroke-[2.5]" />
            <span>Add Property</span>
          </Link>
        </Button>
      </div>

      {/* Filter and Search Bar */}
      <div className="flex flex-col sm:flex-row gap-3">
        <div className="relative flex-1 group">
          <HiMagnifyingGlass className="absolute left-3.5 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400 group-focus-within:text-orange-500 transition-colors pointer-events-none" />
          <Input
            type="text"
            placeholder="Search by title, location, or city..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="pl-10 h-11 text-sm bg-white border-slate-200/90 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-500/15 transition-all placeholder:text-slate-400 shadow-xs"
          />
          {search && (
            <button
              onClick={() => setSearch('')}
              className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1 rounded-md"
            >
              <HiXMark className="w-4 h-4" />
            </button>
          )}
        </div>

        <div className="relative min-w-[170px]">
          <HiFunnel className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none" />
          <select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            className="w-full appearance-none pl-10 pr-10 h-11 rounded-xl border border-slate-200/90 bg-white text-sm font-medium text-slate-700 shadow-xs hover:border-slate-300 focus:border-orange-500 focus:ring-2 focus:ring-orange-500/15 focus:outline-none transition-all cursor-pointer"
          >
            <option value="all">All Statuses</option>
            <option value="approved">Approved</option>
            <option value="pending">Pending</option>
            <option value="rejected">Rejected</option>
          </select>
          <HiChevronDown className="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none" />
        </div>
      </div>

      {/* Content Rendering */}
      {loading ? (
        <PropertiesSkeleton />
      ) : filtered.length === 0 ? (
        <motion.div
          initial={{ opacity: 0, scale: 0.98 }}
          animate={{ opacity: 1, scale: 1 }}
          transition={{ duration: 0.25 }}
          className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-12 text-center my-8"
        >
          <div className="w-16 h-16 bg-orange-50 rounded-2xl flex items-center justify-center mx-auto mb-4 border border-orange-100 text-orange-600 shadow-xs">
            <HiBuildingOffice2 className="w-8 h-8" />
          </div>
          <h3 className="text-lg font-bold text-slate-900 tracking-tight">
            {search || statusFilter !== 'all' ? 'No matching properties found' : 'No properties added yet'}
          </h3>
          <p className="text-slate-500 text-sm mt-1.5 max-w-md mx-auto leading-relaxed">
            {search || statusFilter !== 'all'
              ? "We couldn't find anything matching your search criteria. Try resetting your search or status filter."
              : 'Start monetizing your rental space by creating your very first property listing.'}
          </p>
          {!search && statusFilter === 'all' && (
            <Button
              asChild
              className="mt-6 h-10 px-5 bg-orange-600 hover:bg-orange-700 text-white font-medium text-sm rounded-xl shadow-xs transition-all cursor-pointer inline-flex items-center gap-2"
            >
              <Link to="/owner/properties/add">
                <HiPlus className="w-4 h-4 stroke-[2.5]" />
                <span>Add Your First Property</span>
              </Link>
            </Button>
          )}
        </motion.div>
      ) : (
        <motion.div
          variants={containerVariants}
          initial="hidden"
          animate="visible"
          className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6"
        >
          {filtered.map((p) => (
            <motion.div
              key={p.id}
              variants={cardVariants}
              whileHover={{ y: -4 }}
              className="bg-white rounded-2xl border border-slate-200/80 shadow-xs hover:shadow-xl hover:shadow-slate-200/50 hover:border-slate-300 transition-all duration-200 flex flex-col overflow-hidden group"
            >
              {/* Card Image Wrapper */}
              <div className="h-52 bg-slate-100 relative overflow-hidden">
                {getImageUrl(p.images?.[0]) ? (
                  <img
                    src={getImageUrl(p.images[0])}
                    alt={p.title}
                    className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500 ease-out"
                  />
                ) : (
                  <div className="w-full h-full flex flex-col items-center justify-center text-slate-400 bg-slate-50">
                    <HiBuildingOffice2 className="w-12 h-12 stroke-[1.5]" />
                    <span className="text-xs font-medium mt-1">No Image Available</span>
                  </div>
                )}

                {/* Status Badge */}
                <div className="absolute top-3 right-3">
                  <span
                    className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold ring-1 ring-inset backdrop-blur-md shadow-xs ${statusColors[p.status] || 'bg-slate-100 text-slate-700 ring-slate-200'
                      }`}
                  >
                    <span
                      className={`w-1.5 h-1.5 rounded-full ${statusDots[p.status] || 'bg-slate-400'
                        }`}
                    />
                    {statusLabels[p.status] || p.status}
                  </span>
                </div>
              </div>

              {/* Card Content */}
              <div className="p-5 flex-1 flex flex-col justify-between space-y-4">
                <div className="space-y-1.5">
                  <h3 className="font-bold text-slate-900 group-hover:text-orange-600 transition-colors line-clamp-1 text-base tracking-tight">
                    {p.title}
                  </h3>
                  <div className="flex items-center gap-1.5 text-xs font-medium text-slate-500">
                    <HiMapPin className="w-4 h-4 text-slate-400 shrink-0" />
                    <span className="truncate">{p.city || p.location || 'N/A'}</span>
                  </div>
                </div>

                <div className="pt-2 border-t border-slate-100 flex items-center justify-between">
                  <div className="flex items-baseline gap-1">
                    <span className="text-lg font-extrabold text-slate-900 tracking-tight">
                      {p.price_per_month?.toLocaleString()}
                    </span>
                    <span className="text-xs font-bold text-orange-600">MAD</span>
                    <span className="text-xs text-slate-400 font-normal">/mo</span>
                  </div>

                  <div className="flex items-center gap-2 text-xs font-medium text-slate-500">
                    {p.rooms && (
                      <span className="px-2 py-0.5 rounded-md bg-slate-100 text-slate-600">
                        {p.rooms} rms
                      </span>
                    )}
                    {p.type && (
                      <span className="px-2 py-0.5 rounded-md bg-slate-100 text-slate-600 capitalize">
                        {p.type}
                      </span>
                    )}
                  </div>
                </div>

                {/* Card Actions */}
                <div className="flex gap-2 pt-1">
                  <Button
                    asChild
                    variant="outline"
                    size="sm"
                    className="flex-1 h-9 rounded-xl border-slate-200 hover:bg-slate-50 hover:text-slate-900 font-medium text-xs transition-colors cursor-pointer gap-1.5"
                  >
                    <Link to={`/owner/properties/${p.id}/edit`}>
                      <HiPencilSquare className="w-4 h-4 text-slate-500" />
                      <span>Edit</span>
                    </Link>
                  </Button>
                  <Button
                    onClick={() => setDeleteModal({ open: true, id: p.id, title: p.title })}
                    variant="ghost"
                    size="sm"
                    className="h-9 px-3 rounded-xl text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition-colors cursor-pointer"
                    aria-label="Delete property"
                  >
                    <HiTrash className="w-4 h-4" />
                  </Button>
                </div>
              </div>
            </motion.div>
          ))}
        </motion.div>
      )}

      {/* Confirmation Modal */}
      <ConfirmModal
        isOpen={deleteModal.open}
        title="Delete Property"
        message={`Are you sure you want to delete "${deleteModal.title}"? This action cannot be undone.`}
        onConfirm={handleDelete}
        onCancel={() => setDeleteModal({ open: false, id: null, title: '' })}
        loading={deleting}
      />
    </motion.div>
  );
};

export default Properties;