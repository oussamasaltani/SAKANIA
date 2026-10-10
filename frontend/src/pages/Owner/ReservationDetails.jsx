import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { motion } from 'framer-motion';
import { getReservation, acceptReservation, rejectReservation } from '../../services/ownerService';
import { getImageUrl } from '../../utils/getImageUrl';
import toast from 'react-hot-toast';
import BrandLoader from '../../components/common/BrandLoader';
import {
  HiArrowLeft,
  HiCalendarDays,
  HiUser,
  HiHome,
  HiCurrencyDollar,
  HiCheck,
  HiXMark,
  HiClock,
  HiEnvelope
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

const ReservationDetailsSkeleton = () => (
  <div className="space-y-6">
    <div className="space-y-3">
      <Skeleton className="h-10 w-10 rounded-xl" />
      <Skeleton className="h-8 w-1/3 rounded-lg" />
      <Skeleton className="h-4 w-1/4 rounded-md" />
    </div>
    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div className="lg:col-span-2 space-y-6">
        <div className="rounded-2xl border border-slate-200/80 bg-white overflow-hidden shadow-xs p-5 space-y-4">
          <div className="flex items-center justify-between gap-4">
            <Skeleton className="h-5 w-1/3 rounded-md" />
            <Skeleton className="h-6 w-24 rounded-full" />
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-5 pt-2">
            {[...Array(6)].map((_, i) => (
              <div key={i} className="flex items-start gap-3">
                <Skeleton className="w-10 h-10 rounded-xl shrink-0" />
                <div className="space-y-2 flex-1">
                  <Skeleton className="h-3 w-1/2 rounded-md" />
                  <Skeleton className="h-4 w-2/3 rounded-md" />
                </div>
              </div>
            ))}
          </div>
        </div>
        <div className="rounded-2xl border border-slate-200/80 bg-white overflow-hidden shadow-xs p-5 space-y-4">
          <Skeleton className="h-5 w-1/4 rounded-md" />
          <div className="flex gap-4">
            <Skeleton className="w-24 h-24 rounded-xl shrink-0" />
            <div className="space-y-2.5 flex-1">
              <Skeleton className="h-5 w-2/3 rounded-md" />
              <Skeleton className="h-4 w-1/2 rounded-md" />
              <Skeleton className="h-4 w-1/3 rounded-md" />
            </div>
          </div>
        </div>
      </div>
      <div className="space-y-6">
        <div className="rounded-2xl border border-slate-200/80 bg-white overflow-hidden shadow-xs p-5 space-y-3">
          <Skeleton className="h-5 w-1/3 rounded-md" />
          <Skeleton className="h-11 w-full rounded-xl" />
          <Skeleton className="h-11 w-full rounded-xl" />
        </div>
        <div className="rounded-2xl border border-slate-200/80 bg-white overflow-hidden shadow-xs p-5 space-y-4">
          <Skeleton className="h-5 w-1/2 rounded-md" />
          <div className="flex items-center gap-3">
            <Skeleton className="w-10 h-10 rounded-full shrink-0" />
            <div className="space-y-2 flex-1">
              <Skeleton className="h-4 w-2/3 rounded-md" />
              <Skeleton className="h-3 w-1/2 rounded-md" />
            </div>
          </div>
        </div>
        <Skeleton className="h-11 w-full rounded-xl" />
      </div>
    </div>
  </div>
);

const InfoRow = ({ icon: Icon, label, value }) => (
  <div className="flex items-start gap-3">
    <div className="w-10 h-10 rounded-xl bg-orange-50 text-orange-600 border border-orange-100 flex items-center justify-center shrink-0">
      <Icon className="w-5 h-5" />
    </div>
    <div className="min-w-0">
      <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">{label}</p>
      <p className="text-sm font-semibold text-slate-900 mt-1 break-words">{value || '—'}</p>
    </div>
  </div>
);

const ReservationDetails = () => {
  const { id } = useParams();
  const navigate = useNavigate();
  const [reservation, setReservation] = useState(null);
  const [loading, setLoading] = useState(true);
  const [processing, setProcessing] = useState(false);

  useEffect(() => {
    getReservation(id)
      .then((res) => setReservation(res.data?.data || res.data))
      .catch(() => {
        toast.error('Failed to load reservation');
        navigate('/owner/reservations');
      })
      .finally(() => setLoading(false));
  }, [id, navigate]);

  const handleAccept = async () => {
    setProcessing(true);
    try {
      await acceptReservation(id);
      toast.success('Reservation accepted');
      setReservation((prev) => ({ ...prev, status: 'accepted' }));
    } catch (err) {
      toast.error(err.response?.data?.message || 'Failed to accept');
    } finally {
      setProcessing(false);
    }
  };

  const handleReject = async () => {
    setProcessing(true);
    try {
      await rejectReservation(id);
      toast.success('Reservation rejected');
      setReservation((prev) => ({ ...prev, status: 'rejected' }));
    } catch (err) {
      toast.error(err.response?.data?.message || 'Failed to reject');
    } finally {
      setProcessing(false);
    }
  };

  if (loading) {
    return (
      <div className="p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto antialiased selection:bg-orange-500 selection:text-white">
        <ReservationDetailsSkeleton />
      </div>
    );
  }

  if (!reservation) return null;

  return (
    <motion.div
      initial={{ opacity: 0, y: 12 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.35, ease: 'easeOut' }}
      className="p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto space-y-6 antialiased selection:bg-orange-500 selection:text-white"
    >
      {/* Back Link */}
      <div>
        <Button
          variant="outline"
          size="icon"
          onClick={() => navigate('/owner/reservations')}
          aria-label="Back to reservations"
          className="w-10 h-10 rounded-xl border-slate-200/90 bg-white text-slate-500 hover:bg-slate-50 hover:text-slate-900 hover:border-slate-300 shadow-xs transition-all duration-200 active:scale-[0.98] cursor-pointer"
        >
          <HiArrowLeft className="w-5 h-5" />
        </Button>
      </div>

      {/* Header Section */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-2 border-b border-slate-200/60">
        <div className="relative">
          <div className="flex items-center gap-3">
            {/* Orange Gradient Accent Bar */}
            <div className="h-7 w-1.5 rounded-full bg-gradient-to-b from-orange-500 to-amber-500 shadow-sm" />

            <div className="flex items-center gap-3 flex-wrap">
              <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900 font-display">
                Reservation Details
              </h1>

              <span
                className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold ring-1 ring-inset backdrop-blur-md shadow-xs ${
                  statusColors[reservation.status] || 'bg-slate-100 text-slate-700 ring-slate-200'
                }`}
              >
                <span
                  className={`w-1.5 h-1.5 rounded-full ${
                    statusDots[reservation.status] || 'bg-slate-400'
                  }`}
                />
                {statusLabels[reservation.status] || reservation.status}
              </span>
            </div>
          </div>

          <p className="text-sm text-slate-500 mt-1.5 ml-4.5 font-normal leading-relaxed">
            Reservation #{reservation.id}
          </p>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-2 space-y-6">
          {/* Reservation Information */}
          <motion.section
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.3, ease: 'easeOut' }}
            className="rounded-2xl border border-slate-200/80 shadow-xs bg-white p-5 hover:shadow-md transition-shadow duration-200"
          >
            <div className="flex items-center justify-between gap-4 mb-5">
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-xl bg-orange-50 text-orange-600 border border-orange-100 flex items-center justify-center shrink-0">
                  <HiCalendarDays className="w-5 h-5" />
                </div>
                <h2 className="text-base font-bold text-slate-900 tracking-tight">
                  Reservation Information
                </h2>
              </div>
              <span
                className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold ring-1 ring-inset backdrop-blur-md shadow-xs shrink-0 ${
                  statusColors[reservation.status] || 'bg-slate-100 text-slate-700 ring-slate-200'
                }`}
              >
                <span
                  className={`w-1.5 h-1.5 rounded-full ${
                    statusDots[reservation.status] || 'bg-slate-400'
                  }`}
                />
                {statusLabels[reservation.status] || reservation.status}
              </span>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-5 pt-1">
              <InfoRow icon={HiHome} label="Property" value={reservation.property?.title} />
              <InfoRow icon={HiCurrencyDollar} label="Monthly Rent" value={reservation.property?.price_per_month ? `${reservation.property.price_per_month.toLocaleString()} MAD` : null} />
              <InfoRow icon={HiCalendarDays} label="Start Date" value={reservation.start_date ? new Date(reservation.start_date).toLocaleDateString() : null} />
              <InfoRow icon={HiCalendarDays} label="End Date" value={reservation.end_date ? new Date(reservation.end_date).toLocaleDateString() : 'Ongoing'} />
              <InfoRow icon={HiUser} label="Tenant" value={reservation.tenant?.full_name || reservation.tenant?.name} />
              <InfoRow icon={HiClock} label="Requested On" value={reservation.created_at ? new Date(reservation.created_at).toLocaleDateString() : null} />
            </div>
          </motion.section>

          {reservation.property && (
            <motion.section
              initial={{ opacity: 0, y: 12 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.3, ease: 'easeOut', delay: 0.06 }}
              className="rounded-2xl border border-slate-200/80 shadow-xs bg-white p-5 hover:shadow-md transition-shadow duration-200"
            >
              <div className="flex items-center gap-3 mb-4">
                <div className="w-10 h-10 rounded-xl bg-orange-50 text-orange-600 border border-orange-100 flex items-center justify-center shrink-0">
                  <HiHome className="w-5 h-5" />
                </div>
                <h2 className="text-base font-bold text-slate-900 tracking-tight">
                  Property Details
                </h2>
              </div>
              <div className="flex gap-4">
                <div className="w-24 h-24 bg-slate-50 border border-slate-200/80 rounded-xl flex items-center justify-center overflow-hidden shrink-0">
                  {reservation.property.images?.[0] ? (
                    <img
                      src={getImageUrl(reservation.property.images[0])}
                      alt=""
                      className="w-full h-full object-cover"
                    />
                  ) : (
                    <HiHome className="w-10 h-10 text-slate-300 stroke-[1.5]" />
                  )}
                </div>
                <div className="min-w-0">
                  <h3 className="font-bold text-base tracking-tight text-slate-900">
                    {reservation.property.title}
                  </h3>
                  <p className="text-sm text-slate-500 mt-0.5">
                    {reservation.property.city || reservation.property.location || ''}
                  </p>
                  <p className="text-sm font-bold text-orange-600 mt-1">
                    {reservation.property.price_per_month?.toLocaleString()} MAD/mo
                  </p>
                </div>
              </div>
            </motion.section>
          )}
        </div>

        <div className="space-y-6">
          {reservation.status === 'pending' && (
            <motion.section
              initial={{ opacity: 0, y: 12 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.3, ease: 'easeOut', delay: 0.12 }}
              className="rounded-2xl border border-slate-200/80 shadow-xs bg-white p-5"
            >
              <div className="flex items-center gap-3 mb-4">
                <div className="w-10 h-10 rounded-xl bg-orange-50 text-orange-600 border border-orange-100 flex items-center justify-center shrink-0">
                  <HiCheck className="w-5 h-5" />
                </div>
                <h2 className="text-base font-bold text-slate-900 tracking-tight">Actions</h2>
              </div>
              <div className="space-y-3">
                <Button
                  onClick={handleAccept}
                  disabled={processing}
                  variant="outline"
                  className="w-full h-11 rounded-xl bg-emerald-600 text-white border border-emerald-600 hover:bg-emerald-700 hover:border-emerald-700 font-semibold text-sm shadow-sm hover:shadow-md transition-all duration-200 active:scale-[0.98] cursor-pointer gap-2"
                >
                  {processing ? (
                    <div className="flex items-center justify-center gap-2">
                      <BrandLoader size="small" showText={false} />
                      <span>Accept Reservation</span>
                    </div>
                  ) : (
                    <>
                      <HiCheck className="w-5 h-5 stroke-[2.5]" />
                      <span>Accept Reservation</span>
                    </>
                  )}
                </Button>
                <Button
                  onClick={handleReject}
                  disabled={processing}
                  variant="outline"
                  className="w-full h-11 rounded-xl border-rose-200 text-rose-600 hover:bg-rose-50 hover:border-rose-300 font-semibold text-sm transition-colors cursor-pointer gap-2"
                >
                  <HiXMark className="w-5 h-5 stroke-[2.5]" />
                  <span>Reject Reservation</span>
                </Button>
              </div>
            </motion.section>
          )}

          <motion.section
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.3, ease: 'easeOut', delay: 0.18 }}
            className="rounded-2xl border border-slate-200/80 shadow-xs bg-white p-5"
          >
            <div className="flex items-center gap-3 mb-4">
              <div className="w-10 h-10 rounded-xl bg-orange-50 text-orange-600 border border-orange-100 flex items-center justify-center shrink-0">
                <HiUser className="w-5 h-5" />
              </div>
              <h2 className="text-base font-bold text-slate-900 tracking-tight">Tenant Info</h2>
            </div>
            {reservation.tenant ? (
              <div className="space-y-3">
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 rounded-full bg-orange-50 border border-orange-100 flex items-center justify-center shrink-0">
                    <span className="text-orange-600 text-sm font-bold">
                      {(reservation.tenant.full_name || reservation.tenant.name || 'T').charAt(0)}
                    </span>
                  </div>
                  <div className="min-w-0">
                    <p className="font-semibold text-sm text-slate-900">
                      {reservation.tenant.full_name || reservation.tenant.name}
                    </p>
                    {reservation.tenant.email && (
                      <p className="text-xs text-slate-500 mt-0.5 flex items-center gap-1.5">
                        <HiEnvelope className="w-3.5 h-3.5 shrink-0" />
                        <span className="truncate">{reservation.tenant.email}</span>
                      </p>
                    )}
                  </div>
                </div>
              </div>
            ) : (
              <p className="text-sm text-slate-500">No tenant information available</p>
            )}
          </motion.section>

          <motion.div
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.3, ease: 'easeOut', delay: 0.24 }}
          >
            <Button
              onClick={() => navigate('/owner/reservations')}
              variant="outline"
              className="w-full h-11 rounded-xl border-slate-200/90 bg-white text-slate-700 hover:bg-slate-50 hover:border-slate-300 font-semibold text-sm shadow-xs transition-all duration-200 active:scale-[0.98] cursor-pointer gap-2"
            >
              <HiArrowLeft className="w-4 h-4" />
              <span>Back to Reservations</span>
            </Button>
          </motion.div>
        </div>
      </div>
    </motion.div>
  );
};

export default ReservationDetails;