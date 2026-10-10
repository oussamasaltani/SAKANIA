import { useState, useEffect, useMemo } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import { getReservations, cancelReservation, createReservation } from '../../services/tenantService';
import { getProperties } from '../../services/propertyService';
import { getCheckout, processPayment } from '../../services/paymentService';
import { getImageUrl } from '../../utils/getImageUrl';
import toast from 'react-hot-toast';
import BrandLoader from '../../components/common/BrandLoader';
import {
  HiCalendarDays,
  HiXMark,
  HiExclamationTriangle,
  HiCreditCard,
  HiDocumentText,
  HiCheck,
  HiPlus,
  HiChevronDown,
  HiMapPin,
  HiClipboardDocumentList,
  HiReceiptPercent,
  HiArrowRight,
  HiBuildingOffice2,
  HiClock,
  HiHomeModern
} from 'react-icons/hi2';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
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

const paymentMethods = [
  { value: 'card', label: 'Credit / Debit card' },
  { value: 'cash', label: 'Cash' },
  { value: 'transfer', label: 'Bank transfer' },
];

const formatMoney = (value) => `${Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })} MAD`;

const todayIso = () => new Date().toISOString().slice(0, 10);

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


const MyReservationsSkeleton = () => (
  <div className="flex items-center justify-center py-16">
    <BrandLoader size="default" showText={true} text="Chargement en cours..." />
  </div>
);

const Modal = ({ isOpen, onClose, children, maxWidth = 'max-w-md' }) => {
  if (!isOpen) return null;
  return (
    <AnimatePresence>
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
        <motion.div
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          transition={{ duration: 0.2 }}
          className="absolute inset-0 bg-slate-900/40 backdrop-blur-md"
          onClick={onClose}
        ></motion.div>
        <motion.div
          initial={{ opacity: 0, scale: 0.92, y: 12 }}
          animate={{ opacity: 1, scale: 1, y: 0 }}
          exit={{ opacity: 0, scale: 0.95, y: 8 }}
          transition={{ type: 'spring', damping: 25, stiffness: 320 }}
          className={`relative w-full ${maxWidth} bg-white rounded-2xl border border-slate-200/80 p-6 shadow-2xl shadow-slate-900/10 z-10 max-h-[90vh] overflow-y-auto`}
        >
          <button
            onClick={onClose}
            className="absolute top-4 right-4 p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100 transition-colors cursor-pointer"
            aria-label="Close"
          >
            <HiXMark className="w-5 h-5" />
          </button>
          {children}
        </motion.div>
      </div>
    </AnimatePresence>
  );
};

const ConfirmModal = ({ isOpen, onConfirm, onCancel, loading }) => {
  if (!isOpen) return null;
  return (
    <Modal isOpen={isOpen} onClose={onCancel}>
      <div className="w-12 h-12 rounded-2xl bg-rose-50 text-rose-600 border border-rose-100 flex items-center justify-center mx-auto mb-4 shadow-xs">
        <HiExclamationTriangle className="w-6 h-6" />
      </div>
      <h3 className="text-lg font-bold text-slate-900 text-center tracking-tight">Cancel Reservation</h3>
      <p className="text-sm text-slate-500 text-center mt-2 leading-relaxed">
        Are you sure you want to cancel this reservation? This action cannot be undone.
      </p>
      <div className="flex gap-3 mt-6">
        <Button
          variant="outline"
          onClick={onCancel}
          disabled={loading}
          className="flex-1 h-11 rounded-xl border-slate-200 hover:bg-slate-50 hover:border-slate-300 font-semibold text-slate-700 cursor-pointer"
        >
          Keep it
        </Button>
        <Button
          variant="destructive"
          onClick={onConfirm}
          disabled={loading}
          className="flex-1 h-11 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-semibold text-sm shadow-md transition-all duration-200 active:scale-[0.98] cursor-pointer"
        >
          {loading ? (
            <div className="flex items-center justify-center gap-2">
              <BrandLoader size="small" showText={false} />
              <span>Cancelling...</span>
            </div>
          ) : (
            'Yes, Cancel'
          )}
        </Button>
      </div>
    </Modal>
  );
};

const NewReservationForm = ({ properties, initialPropertyId, onCreated, submitting }) => {
  const [propertyId, setPropertyId] = useState(initialPropertyId ? String(initialPropertyId) : '');
  const [startDate, setStartDate] = useState(todayIso());
  const [durationMonths, setDurationMonths] = useState(6);

  useEffect(() => {
    if (initialPropertyId) setPropertyId(String(initialPropertyId));
  }, [initialPropertyId]);

  const property = properties.find((p) => String(p.id) === String(propertyId));
  const months = Math.max(1, parseInt(durationMonths, 10) || 0);
  const total = property ? Number(property.price_per_month || 0) * months : 0;
  const endDate = useMemo(() => {
    if (!startDate || !months) return null;
    const date = new Date(`${startDate}T00:00:00`);
    date.setMonth(date.getMonth() + months);
    return date.toISOString().slice(0, 10);
  }, [startDate, months]);

  const handleSubmit = (e) => {
    e.preventDefault();
    onCreated({ property_id: Number(propertyId), start_date: startDate, duration_months: months });
  };

  return (
    <motion.form
      onSubmit={handleSubmit}
      className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-5 sm:p-6 space-y-5"
    >
      <div className="flex items-center gap-3 pb-4 border-b border-slate-100">
        <div className="w-10 h-10 rounded-xl bg-orange-50 text-orange-600 border border-orange-100 flex items-center justify-center shrink-0 shadow-xs">
          <HiCalendarDays className="w-5 h-5" />
        </div>
        <div className="min-w-0">
          <h2 className="text-base font-bold text-slate-900 tracking-tight">Request a reservation</h2>
          <p className="text-xs text-slate-500 mt-0.5">
            The owner reviews your request before payment is enabled.
          </p>
        </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div className="sm:col-span-3">
          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 mb-1.5">
            Property
          </label>
          <div className="relative group">
            <HiBuildingOffice2 className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 group-focus-within:text-orange-500 transition-colors pointer-events-none z-10" />
            <select
              value={propertyId}
              onChange={(e) => setPropertyId(e.target.value)}
              required
              className="w-full appearance-none pl-10 pr-10 h-11 rounded-xl border border-slate-200/90 bg-white text-sm font-medium text-slate-700 shadow-xs hover:border-slate-300 focus:border-orange-500 focus:ring-2 focus:ring-orange-500/15 focus:outline-none transition-all cursor-pointer"
            >
              <option value="">Select a property</option>
              {properties.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.title} — {formatMoney(p.price_per_month)}/mo
                </option>
              ))}
            </select>
            <HiChevronDown className="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none" />
          </div>
        </div>

        <div>
          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 mb-1.5">
            Start date
          </label>
          <Input
            type="date"
            min={todayIso()}
            value={startDate}
            onChange={(e) => setStartDate(e.target.value)}
            required
            className="h-11 text-sm bg-white border-slate-200/90 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-500/15 transition-all text-slate-900 shadow-xs"
          />
        </div>

        <div>
          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 mb-1.5">
            Duration (months)
          </label>
          <Input
            type="number"
            min="1"
            max="36"
            value={durationMonths}
            onChange={(e) => setDurationMonths(e.target.value)}
            required
            className="h-11 text-sm bg-white border-slate-200/90 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-500/15 transition-all text-slate-900 shadow-xs"
          />
        </div>

        <div>
          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 mb-1.5">
            Estimated total
          </label>
          <div className="h-11 flex items-center rounded-xl border border-orange-200/80 bg-orange-50/70 px-4 text-sm font-extrabold text-orange-700">
            {property ? formatMoney(total) : '—'}
          </div>
        </div>
      </div>

      {property && endDate && (
        <div className="flex items-center gap-2 text-xs font-medium text-slate-500 bg-slate-50 border border-slate-200/80 rounded-xl px-3.5 py-2.5">
          <HiClock className="w-4 h-4 text-slate-400 shrink-0" />
          <span>
            {new Date(`${startDate}T00:00:00`).toLocaleDateString()} to{' '}
            {new Date(`${endDate}T00:00:00`).toLocaleDateString()} ({months} month
            {months > 1 ? 's' : ''})
          </span>
        </div>
      )}

      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-end gap-3 pt-1">
        <Button
          type="submit"
          disabled={submitting || !propertyId}
          className="h-11 px-5 bg-slate-900 hover:bg-slate-800 text-white font-semibold text-sm rounded-xl shadow-sm hover:shadow-md active:scale-[0.98] transition-all duration-200 border border-slate-800 no-underline hover:no-underline cursor-pointer"
        >
          {submitting ? (
            <div className="flex items-center justify-center gap-2">
              <BrandLoader size="small" showText={false} />
              <span>Sending request...</span>
            </div>
          ) : (
            <>
              <HiArrowRight className="w-4 h-4 text-orange-500 stroke-[2.5]" />
              <span>Send reservation request</span>
            </>
          )}
        </Button>
      </div>
    </motion.form>
  );
};

const CheckoutModal = ({ checkout, loading, onClose, onPaid }) => {
  const [amount, setAmount] = useState('');
  const [method, setMethod] = useState('card');
  const [paying, setPaying] = useState(false);

  useEffect(() => {
    if (checkout) {
      setAmount(String(checkout.amount_due ?? ''));
      setMethod('card');
    }
  }, [checkout]);

  if (!checkout && !loading) return null;

  const due = Number(checkout?.amount_due || 0);
  const entered = Number(amount || 0);
  const isValid = entered > 0 && entered <= due + 0.001;

  const handlePay = async () => {
    setPaying(true);
    try {
      const res = await processPayment({
        reservation_id: checkout.reservation.id,
        amount: entered,
        method,
      });
      onPaid(res.data);
    } catch (err) {
      toast.error(err.response?.data?.message || 'Payment failed');
    } finally {
      setPaying(false);
    }
  };

  return (
    <Modal isOpen={true} onClose={onClose} maxWidth="max-w-lg">
      <div className="w-12 h-12 rounded-2xl bg-orange-50 text-orange-600 border border-orange-100 flex items-center justify-center mx-auto mb-4 shadow-xs">
        <HiCreditCard className="w-6 h-6" />
      </div>
      <h3 className="text-lg font-bold text-slate-900 text-center tracking-tight">Complete your payment</h3>
      <p className="text-sm text-slate-500 text-center mt-1.5">
        {checkout?.reservation?.property?.title}
      </p>

      {loading || !checkout ? (
        <div className="space-y-3 mt-6">
          <Skeleton className="h-10 w-full rounded-xl" />
          <Skeleton className="h-10 w-full rounded-xl" />
        </div>
      ) : (
        <>
          <div className="mt-6 rounded-xl border border-slate-200/80 bg-slate-50/80 p-4 space-y-2.5 text-sm">
            <div className="flex justify-between gap-3">
              <span className="text-slate-500 font-medium">Total rental amount</span>
              <span className="font-semibold text-slate-900">{formatMoney(checkout.total_amount)}</span>
            </div>
            <div className="flex justify-between gap-3">
              <span className="text-slate-500 font-medium">Already paid</span>
              <span className="font-semibold text-slate-900">{formatMoney(checkout.paid_amount)}</span>
            </div>
            <div className="flex justify-between gap-3 border-t border-slate-200/80 pt-2.5">
              <span className="text-slate-900 font-bold">Amount due</span>
              <span className="font-extrabold text-orange-600">{formatMoney(due)}</span>
            </div>
          </div>

          <div className="mt-4 space-y-4">
            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 mb-1.5">
                Amount (MAD)
              </label>
              <Input
                type="number"
                min="0.01"
                max={due}
                step="0.01"
                value={amount}
                onChange={(e) => setAmount(e.target.value)}
                className="h-11 text-sm bg-white border-slate-200/90 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-500/15 transition-all text-slate-900 shadow-xs"
              />
              {!isValid && amount !== '' && (
                <p className="text-xs font-medium text-rose-600 mt-1.5">
                  Enter an amount between 0.01 and {formatMoney(due)}.
                </p>
              )}
            </div>

            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 mb-1.5">
                Payment method
              </label>
              <div className="relative group">
                <HiReceiptPercent className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 group-focus-within:text-orange-500 transition-colors pointer-events-none" />
                <select
                  value={method}
                  onChange={(e) => setMethod(e.target.value)}
                  className="w-full appearance-none pl-10 pr-10 h-11 rounded-xl border border-slate-200/90 bg-white text-sm font-medium text-slate-700 shadow-xs hover:border-slate-300 focus:border-orange-500 focus:ring-2 focus:ring-orange-500/15 focus:outline-none transition-all cursor-pointer"
                >
                  {paymentMethods.map((m) => (
                    <option key={m.value} value={m.value}>
                      {m.label}
                    </option>
                  ))}
                </select>
                <HiChevronDown className="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none" />
              </div>
            </div>
          </div>

          <p className="text-xs text-slate-400 mt-4 leading-relaxed">
            Your rental contract is generated automatically after your first payment. Every later
            payment is confirmed with a payment receipt you can download.
          </p>

          <div className="flex gap-3 mt-5">
            <Button
              variant="outline"
              onClick={onClose}
              disabled={paying}
              className="flex-1 h-11 rounded-xl border-slate-200 hover:bg-slate-50 hover:border-slate-300 font-semibold text-slate-700 cursor-pointer"
            >
              Close
            </Button>
            <Button
              onClick={handlePay}
              disabled={paying || !isValid}
              className="flex-1 h-11 px-5 bg-slate-900 hover:bg-slate-800 text-white font-semibold text-sm rounded-xl shadow-sm hover:shadow-md active:scale-[0.98] transition-all duration-200 border border-slate-800 no-underline hover:no-underline cursor-pointer"
            >
              {paying ? (
                <div className="flex items-center justify-center gap-2">
                  <BrandLoader size="small" showText={false} />
                  <span>Processing...</span>
                </div>
              ) : (
                `Pay ${formatMoney(entered)}`
              )}
            </Button>
          </div>
        </>
      )}
    </Modal>
  );
};

const MyReservations = () => {
  const [searchParams, setSearchParams] = useSearchParams();
  const [reservations, setReservations] = useState([]);
  const [properties, setProperties] = useState([]);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [showForm, setShowForm] = useState(false);
  const [cancelModal, setCancelModal] = useState({ open: false, id: null });
  const [cancelling, setCancelling] = useState(false);
  const [checkout, setCheckout] = useState(null);
  const [checkoutLoading, setCheckoutLoading] = useState(false);
  const [showCheckout, setShowCheckout] = useState(false);

  const preselectedPropertyId = searchParams.get('property_id');

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

  useEffect(() => {
    getProperties({ per_page: 100 })
      .then((res) => setProperties(res.data?.data || res.data || []))
      .catch(() => {});
  }, []);

  useEffect(() => {
    if (preselectedPropertyId) {
      setShowForm(true);
    }
  }, [preselectedPropertyId]);

  const closeForm = () => {
    setShowForm(false);
    if (preselectedPropertyId) {
      searchParams.delete('property_id');
      setSearchParams(searchParams, { replace: true });
    }
  };

  const handleCreate = async (payload) => {
    setSubmitting(true);
    try {
      const res = await createReservation(payload);
      toast.success(res.data?.message || 'Reservation request sent');
      closeForm();
      fetchReservations();
    } catch (err) {
      toast.error(err.response?.data?.message || 'Failed to send the reservation request');
    } finally {
      setSubmitting(false);
    }
  };

  const handleCancel = async () => {
    if (!cancelModal.id) return;
    setCancelling(true);
    try {
      await cancelReservation(cancelModal.id);
      toast.success('Reservation cancelled successfully');
      setReservations((prev) => prev.map((r) => (r.id === cancelModal.id ? { ...r, status: 'cancelled' } : r)));
      setCancelModal({ open: false, id: null });
    } catch (err) {
      toast.error(err.response?.data?.message || 'Failed to cancel reservation');
    } finally {
      setCancelling(false);
    }
  };

  const handleOpenCheckout = async (reservation) => {
    setShowCheckout(true);
    setCheckout(null);
    setCheckoutLoading(true);
    try {
      const res = await getCheckout(reservation.id);
      setCheckout(res.data);
    } catch (err) {
      toast.error(err.response?.data?.message || 'Unable to start the payment');
      setShowCheckout(false);
    } finally {
      setCheckoutLoading(false);
    }
  };

  const handlePaid = (data) => {
    setShowCheckout(false);
    setCheckout(null);
    toast.success(data?.message || 'Payment completed');

    if (data?.contract) {
      toast.success('Your rental contract is ready to download');
    } else if (data?.contract_error) {
      toast.error(data.contract_error);
    } else if (data?.receipt) {
      toast.success('Payment receipt ready to download');
    }

    setReservations((prev) =>
      prev.map((r) =>
        r.id === data?.payment?.reservation_id
          ? {
              ...r,
              contract_id: data?.contract?.id ?? r.contract_id,
              paid_amount: data?.payment?.paid_amount ?? r.paid_amount,
              remaining_balance: data?.payment?.remaining_balance ?? r.remaining_balance,
            }
          : r
      )
    );
    fetchReservations();
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
                My Reservations
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
            Track and manage your property reservations
          </p>
        </div>

        <AnimatePresence initial={false} mode="wait">
          {!showForm && (
            <motion.div
              key="new-reservation-cta"
              initial={{ opacity: 0, scale: 0.96 }}
              animate={{ opacity: 1, scale: 1 }}
              exit={{ opacity: 0, scale: 0.96 }}
              transition={{ duration: 0.2, ease: 'easeOut' }}
            >
              <Button
                onClick={() => setShowForm(true)}
                className="shrink-0 h-11 px-5 bg-slate-900 hover:bg-slate-800 text-white font-semibold text-sm rounded-xl shadow-sm hover:shadow-md active:scale-[0.98] transition-all duration-200 border border-slate-800 no-underline hover:no-underline cursor-pointer"
              >
                <HiPlus className="w-5 h-5 text-orange-500 stroke-[2.5]" />
                New reservation
              </Button>
            </motion.div>
          )}
        </AnimatePresence>
      </div>

      {/* New Reservation Form */}
      <AnimatePresence initial={false}>
        {showForm && (
          <motion.div
            key="reservation-form"
            initial={{ opacity: 0, y: -10 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: -10 }}
            transition={{ duration: 0.25, ease: 'easeOut' }}
          >
            <NewReservationForm
              properties={properties}
              initialPropertyId={preselectedPropertyId}
              onCreated={handleCreate}
              submitting={submitting}
            />
          </motion.div>
        )}
      </AnimatePresence>

      {/* Content Rendering */}
      {loading ? (
        <MyReservationsSkeleton />
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
            You haven&rsquo;t made any reservations. Browse properties and submit a reservation
            request to get started.
          </p>
          <div className="flex flex-wrap items-center justify-center gap-3 mt-6">
            <Button
              asChild
              className="h-11 px-5 bg-slate-900 hover:bg-slate-800 text-white font-semibold text-sm rounded-xl shadow-sm hover:shadow-md active:scale-[0.98] transition-all duration-200 border border-slate-800 no-underline hover:no-underline cursor-pointer"
            >
              <Link to="/properties" className="flex items-center gap-2 no-underline text-white">
                <HiArrowRight className="w-5 h-5 text-orange-500 stroke-[2.5]" />
                <span>Browse Properties</span>
              </Link>
            </Button>
            <Button
              variant="outline"
              onClick={() => setShowForm(true)}
              className="h-11 px-5 rounded-xl border-slate-200 hover:bg-slate-50 hover:border-slate-300 font-semibold text-slate-700 cursor-pointer"
            >
              <HiClipboardDocumentList className="w-4 h-4 text-slate-500" />
              Request a reservation
            </Button>
          </div>
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
                  <HiHomeModern className="w-10 h-10 text-slate-300 stroke-[1.5]" />
                )}
              </div>

              <div className="flex-1 min-w-0">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <h3 className="font-bold text-base tracking-tight text-slate-900 group-hover:text-orange-600 transition-colors duration-200 truncate">
                      {r.property?.title || 'Property'}
                    </h3>
                    {r.property?.location && (
                      <p className="text-sm text-slate-500 mt-1 flex items-center gap-1.5">
                        <HiMapPin className="w-4 h-4 text-slate-400 shrink-0" />
                        <span className="truncate">{r.property.location}</span>
                      </p>
                    )}
                    {r.reference && (
                      <span className="inline-flex items-center mt-2 px-2.5 py-1 rounded-lg bg-slate-50 border border-slate-200/80 text-[11px] font-semibold text-slate-500 font-mono">
                        {r.reference}
                      </span>
                    )}
                  </div>

                  {/* Status Badge */}
                  <span
                    className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold ring-1 ring-inset backdrop-blur-md shadow-xs shrink-0 ${statusColors[r.status] || 'bg-slate-100 text-slate-700 ring-slate-200'
                      }`}
                  >
                    <span className={`w-1.5 h-1.5 rounded-full ${statusDots[r.status] || 'bg-slate-400'}`} />
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
                    <span className="flex items-baseline gap-1 font-semibold text-orange-600">
                      <span>{Number(r.property.price_per_month).toLocaleString()}</span>
                      <span className="text-xs font-bold">MAD</span>
                      <span className="text-xs font-normal text-slate-400">/mo</span>
                    </span>
                  )}
                </div>

                {/* Card Actions */}
                {r.status === 'pending' && (
                  <div className="flex items-center gap-3 mt-4 pt-3 border-t border-slate-100">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setCancelModal({ open: true, id: r.id })}
                      className="h-9 rounded-xl border-rose-200 text-rose-600 hover:bg-rose-50 hover:border-rose-300 font-semibold text-xs transition-colors duration-200 cursor-pointer gap-1.5"
                    >
                      <HiXMark className="w-4 h-4 stroke-[2.5]" />
                      <span>Cancel Reservation</span>
                    </Button>
                  </div>
                )}

                {r.status === 'accepted' && (
                  <div className="flex flex-wrap items-center gap-3 mt-4 pt-3 border-t border-slate-100">
                    {Number(r.remaining_balance) > 0 && (
                      <Button
                        size="sm"
                        onClick={() => handleOpenCheckout(r)}
                        className="h-9 px-3 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-semibold text-xs shadow-sm hover:shadow-md active:scale-[0.98] transition-all duration-200 border border-slate-800 no-underline hover:no-underline cursor-pointer gap-1.5"
                      >
                        <HiCreditCard className="w-4 h-4 text-orange-500 stroke-[2.5]" />
                        {r.contract_id ? 'Pay next month' : 'Pay now'}
                      </Button>
                    )}
                    {r.contract_id ? (
                      <>
                        {Number(r.remaining_balance) <= 0 && (
                          <span className="inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-700 bg-emerald-50 ring-1 ring-inset ring-emerald-600/20 rounded-full px-3 py-1.5">
                            <HiCheck className="w-3.5 h-3.5 stroke-[2.5]" />
                            Paid — contract ready
                          </span>
                        )}
                        <Button
                          asChild
                          size="sm"
                          variant="outline"
                          className="h-9 px-3 rounded-xl border-slate-200 hover:bg-slate-50 hover:text-slate-900 font-medium text-xs transition-colors duration-200 cursor-pointer gap-1.5 no-underline hover:no-underline"
                        >
                          <Link to={`/tenant/contracts/${r.contract_id}`}>
                            <HiDocumentText className="w-4 h-4 text-slate-500" />
                            <span>View contract</span>
                          </Link>
                        </Button>
                      </>
                    ) : null}
                  </div>
                )}
              </div>
            </motion.div>
          ))}
        </motion.div>
      )}

      <ConfirmModal
        isOpen={cancelModal.open}
        onConfirm={handleCancel}
        onCancel={() => setCancelModal({ open: false, id: null })}
        loading={cancelling}
      />

      {showCheckout && (
        <CheckoutModal
          checkout={checkout}
          loading={checkoutLoading}
          onClose={() => {
            setShowCheckout(false);
            setCheckout(null);
          }}
          onPaid={handlePaid}
        />
      )}
    </motion.div>
  );
};

export default MyReservations;