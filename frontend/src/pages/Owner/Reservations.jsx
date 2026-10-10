import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import { getReservations, acceptReservation, rejectReservation } from '../../services/ownerService';
import { getImageUrl } from '../../utils/getImageUrl';
import toast from 'react-hot-toast';
import BrandLoader from '../../components/common/BrandLoader';
import {
  HiHome,
  HiCalendarDays,
  HiUser,
  HiCheck,
  HiXMark,
  HiChevronRight
} from 'react-icons/hi2';
import { Button } from '../../components/ui/button';
import { Skeleton } from '../../components/ui/skeleton';

const statusColors = {
  pending: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  accepted: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  rejected: 'bg-rose-50 text-rose-700 ring-rose-600/20',
  cancelled: 'bg-slate-100 text-slate-600 ring-slate-500/20',
};

const statusDots = {
  pending: 'bg-amber-500',
  accepted: 'bg-emerald-500',
  rejected: 'bg-rose-500',
  cancelled: 'bg-slate-400',
};

const statusLabels = {
  pending: 'Pending',
  accepted: 'Accepted',
  rejected: 'Rejected',
  cancelled: 'Cancelled',
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

const ReservationsSkeleton = () => (
  <div className="space-y-4">
    {[...Array(4)].map((_, i) => (
      <div
        key={i}
        className="rounded-2xl border border-slate-200/80 bg-white overflow-hidden shadow-xs p-5 flex flex-col sm:flex-row gap-5"
      >
        <Skeleton className="w-full sm:w-32 h-28 rounded-xl shrink-0" />
        <div className="flex-1 min-w-0 space-y-3.5">
          <div className="flex items-start justify-between gap-3">
            <Skeleton className="h-5 w-1/3 rounded-md" />
            <Skeleton className="h-6 w-24 rounded-full" />
          </div>
          <Skeleton className="h-4 w-1/4 rounded-md" />
          <Skeleton className="h-4 w-1/2 rounded-md" />
          <div className="pt-1 flex items-center gap-3">
            <Skeleton className="h-9 flex-1 rounded-xl" />
            <Skeleton className="h-9 flex-1 rounded-xl" />
            <Skeleton className="h-9 w-24 rounded-xl" />
          </div>
        </div>
      </div>
    ))}
  </div>
);

const ConfirmModal = ({ isOpen, title, message, confirmText, onConfirm, onCancel, loading, variant = 'danger' }) => {
  if (!isOpen) return null;
  const Icon = variant === 'success' ? HiCheck : HiXMark;

  return (
    <AnimatePresence>
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
        <motion.div
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          transition={{ duration: 0.2 }}
          className="absolute inset-0 bg-slate-900/40 backdrop-blur-md"
          onClick={onCancel}
        ></motion.div>
        <motion.div
          initial={{ opacity: 0, scale: 0.92, y: 12 }}
          animate={{ opacity: 1, scale: 1, y: 0 }}
          exit={{ opacity: 0, scale: 0.95, y: 8 }}
          transition={{ type: 'spring', damping: 25, stiffness: 320 }}
          className="relative w-full max-w-md bg-white rounded-2xl border border-slate-200/80 p-6 shadow-2xl shadow-slate-900/10 z-10"
        >
          <button
            onClick={onCancel}
            className="absolute top-4 right-4 p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100 transition-colors cursor-pointer"
          >
            <HiXMark className="w-5 h-5" />
          </button>

          <div
            className={`w-12 h-12 rounded-2xl flex items-center justify-center mx-auto mb-4 border shadow-xs ${
              variant === 'success'
                ? 'bg-emerald-50 text-emerald-600 border-emerald-100'
                : 'bg-rose-50 text-rose-600 border-rose-100'
            }`}
          >
            <Icon className="w-6 h-6" />
          </div>

          <h3 className="text-lg font-bold text-slate-900 text-center tracking-tight">{title}</h3>
          <p className="text-sm text-slate-500 text-center mt-2 leading-relaxed">{message}</p>

          <div className="flex gap-3 mt-6">
            <Button
              variant="outline"
              onClick={onCancel}
              disabled={loading}
              className="flex-1 h-11 rounded-xl border-slate-200 hover:bg-slate-50 hover:border-slate-300 font-semibold text-slate-700 cursor-pointer"
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={onConfirm}
              disabled={loading}
              className={`flex-1 h-11 rounded-xl text-white font-semibold text-sm shadow-sm transition-all duration-200 active:scale-[0.98] cursor-pointer ${
                variant === 'success'
                  ? 'bg-emerald-600 hover:bg-emerald-700 shadow-md'
                  : 'bg-rose-600 hover:bg-rose-700 shadow-md'
              }`}
            >
              {loading ? (
                <div className="flex items-center justify-center gap-2">
                  <BrandLoader size="small" showText={false} />
                  <span>Processing...</span>
                </div>
              ) : (
                confirmText
              )}
            </Button>
          </div>
        </motion.div>
      </div>
    </AnimatePresence>
  );
};

const Reservations = () => {
  const [reservations, setReservations] = useState([]);
  const [loading, setLoading] = useState(true);
  const [actionModal, setActionModal] = useState({ open: false, id: null, action: null, title: '' });
  const [processing, setProcessing] = useState(false);

  const fetchReservations = () => {
    setLoading(true);
    getReservations()
      .then((res) => setReservations(res.data?.data || res.data || []))
      .catch(() => toast.error('Failed to load reservations'))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    fetchReservations();
  }, []);

  const handleAction = async () => {
    if (!actionModal.id || !actionModal.action) return;
    setProcessing(true);
    try {
      if (actionModal.action === 'accept') {
        await acceptReservation(actionModal.id);
        toast.success('Reservation accepted');
        setReservations((prev) => prev.map((r) => r.id === actionModal.id ? { ...r, status: 'accepted' } : r));
      } else {
        await rejectReservation(actionModal.id);
        toast.success('Reservation rejected');
        setReservations((prev) => prev.map((r) => r.id === actionModal.id ? { ...r, status: 'rejected' } : r));
      }
      setActionModal({ open: false, id: null, action: null, title: '' });
    } catch (err) {
      toast.error(err.response?.data?.message || 'Action failed');
    } finally {
      setProcessing(false);
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
                Reservations
              </h1>

              {/* Count Badge with Live Pulse Dot */}
              <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-orange-50 border border-orange-200/80 text-orange-700 shadow-2xs">
                <span className="relative flex h-2 w-2">
                  <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-orange-400 opacity-75"></span>
                  <span className="relative inline-flex rounded-full h-2 w-2 bg-orange-500"></span>
                </span>
                {reservations.length} {reservations.length === 1 ? 'Reservation' : 'Reservations'}
              </span>
            </div>
          </div>

          <p className="text-sm text-slate-500 mt-1.5 ml-4.5 font-normal leading-relaxed">
            Manage reservation requests from tenants
          </p>
        </div>
      </div>

      {/* Content Rendering */}
      {loading ? (
        <ReservationsSkeleton />
      ) : reservations.length === 0 ? (
        <motion.div
          initial={{ opacity: 0, scale: 0.98 }}
          animate={{ opacity: 1, scale: 1 }}
          transition={{ duration: 0.25 }}
          className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-12 text-center my-8"
        >
          <div className="w-16 h-16 bg-orange-50 rounded-2xl flex items-center justify-center mx-auto mb-4 border border-orange-100 text-orange-600 shadow-xs">
            <HiCalendarDays className="w-8 h-8" />
          </div>
          <h3 className="text-lg font-bold text-slate-900 tracking-tight">No Reservations Yet</h3>
          <p className="text-slate-500 text-sm mt-1.5 max-w-md mx-auto leading-relaxed">
            When tenants request to rent your properties, their reservations will appear here.
          </p>
        </motion.div>
      ) : (
        <motion.div
          variants={containerVariants}
          initial="hidden"
          animate="visible"
          className="space-y-4"
        >
          {reservations.map((r) => (
            <motion.div
              key={r.id}
              variants={cardVariants}
              whileHover={{ y: -4 }}
              className="bg-white rounded-2xl border border-slate-200/80 shadow-xs hover:shadow-xl hover:shadow-slate-200/50 hover:border-slate-300 transition-all duration-200 p-5 flex flex-col sm:flex-row gap-5 group"
            >
              {/* Thumbnail */}
              <div className="w-full sm:w-32 h-28 bg-slate-50 border border-slate-200/80 rounded-xl flex items-center justify-center overflow-hidden shrink-0">
                {r.property?.images?.[0] ? (
                  <img
                    src={getImageUrl(r.property.images[0])}
                    alt=""
                    className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500 ease-out"
                  />
                ) : (
                  <HiHome className="w-10 h-10 text-slate-300 stroke-[1.5]" />
                )}
              </div>

              <div className="flex-1 min-w-0">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <h3 className="font-bold text-base tracking-tight text-slate-900 group-hover:text-orange-600 transition-colors truncate">
                      {r.property?.title || 'Property'}
                    </h3>
                    {r.tenant && (
                      <p className="text-sm text-slate-500 mt-0.5 flex items-center gap-1.5">
                        <HiUser className="w-4 h-4 text-slate-400 shrink-0" />
                        <span className="truncate">
                          {r.tenant.full_name || r.tenant.name || 'Tenant'}
                        </span>
                      </p>
                    )}
                  </div>

                  {/* Status Badge */}
                  <span
                    className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold ring-1 ring-inset backdrop-blur-md shadow-xs shrink-0 ${
                      statusColors[r.status] || 'bg-slate-100 text-slate-700 ring-slate-200'
                    }`}
                  >
                    <span
                      className={`w-1.5 h-1.5 rounded-full ${
                        statusDots[r.status] || 'bg-slate-400'
                      }`}
                    />
                    {statusLabels[r.status] || r.status}
                  </span>
                </div>

                <div className="flex flex-wrap items-center gap-4 mt-3 text-sm text-slate-500">
                  {r.start_date && (
                    <span className="flex items-center gap-1.5 font-medium">
                      <HiCalendarDays className="w-4 h-4 text-slate-400 shrink-0" />
                      {new Date(r.start_date).toLocaleDateString()} – {r.end_date ? new Date(r.end_date).toLocaleDateString() : 'Ongoing'}
                    </span>
                  )}
                  {r.property?.price_per_month && (
                    <span className="font-semibold text-orange-600">
                      {r.property.price_per_month.toLocaleString()} MAD/mo
                    </span>
                  )}
                </div>

                {/* Card Actions */}
                <div className="flex items-center gap-3 mt-4 pt-3 border-t border-slate-100">
                  {r.status === 'pending' && (
                    <>
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setActionModal({ open: true, id: r.id, action: 'accept', title: 'Accept Reservation' })}
                        className="flex-1 h-9 rounded-xl border-emerald-200 text-emerald-700 hover:bg-emerald-50 hover:border-emerald-300 font-semibold text-xs transition-colors cursor-pointer gap-1.5"
                      >
                        <HiCheck className="w-4 h-4 stroke-[2.5]" />
                        <span>Accept</span>
                      </Button>
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setActionModal({ open: true, id: r.id, action: 'reject', title: 'Reject Reservation' })}
                        className="flex-1 h-9 rounded-xl border-rose-200 text-rose-600 hover:bg-rose-50 hover:border-rose-300 font-semibold text-xs transition-colors cursor-pointer gap-1.5"
                      >
                        <HiXMark className="w-4 h-4 stroke-[2.5]" />
                        <span>Reject</span>
                      </Button>
                    </>
                  )}
                  <Link
                    to={`/owner/reservations/${r.id}`}
                    className="ml-auto text-xs font-semibold text-slate-500 hover:text-orange-600 inline-flex items-center gap-1 no-underline hover:no-underline transition-colors duration-200 active:scale-[0.98]"
                  >
                    Details
                    <HiChevronRight className="w-4 h-4" />
                  </Link>
                </div>
              </div>
            </motion.div>
          ))}
        </motion.div>
      )}

      {/* Confirmation Modal */}
      <ConfirmModal
        isOpen={actionModal.open}
        title={actionModal.title}
        message={actionModal.action === 'accept'
          ? 'Are you sure you want to accept this reservation? A contract will be created.'
          : 'Are you sure you want to reject this reservation?'}
        confirmText={actionModal.action === 'accept' ? 'Accept' : 'Reject'}
        variant={actionModal.action === 'accept' ? 'success' : 'danger'}
        onConfirm={handleAction}
        onCancel={() => setActionModal({ open: false, id: null, action: null, title: '' })}
        loading={processing}
      />
    </motion.div>
  );
};

export default Reservations;