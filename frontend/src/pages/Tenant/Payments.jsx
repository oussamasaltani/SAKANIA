import { useState, useEffect } from 'react';
import { motion } from 'framer-motion';
import { getPayments } from '../../services/tenantService';
import { downloadReceipt, normalizePayments } from '../../services/paymentService';
import toast from 'react-hot-toast';
import BrandLoader from '../../components/common/BrandLoader';
import {
  HiCreditCard,
  HiCheckCircle,
  HiClock,
  HiXCircle,
  HiWallet,
  HiBanknotes,
  HiArrowTrendingUp,
  HiDocumentText
} from 'react-icons/hi2';
import { Card } from '../../components/ui/card';
import { Button } from '../../components/ui/button';
import { Progress } from '../../components/ui/progress';
import { Skeleton } from '../../components/ui/skeleton';

const statusColors = {
  paid: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  completed: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  pending: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  failed: 'bg-rose-50 text-rose-700 ring-rose-600/20',
  cancelled: 'bg-rose-50 text-rose-700 ring-rose-600/20',
};

const statusLabels = {
  paid: 'Paid',
  completed: 'Completed',
  pending: 'Pending',
  failed: 'Failed',
  cancelled: 'Cancelled',
};

const statusIcons = {
  paid: HiCheckCircle,
  completed: HiCheckCircle,
  pending: HiClock,
  failed: HiXCircle,
  cancelled: HiXCircle,
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

const PaymentsSkeleton = () => (
  <div className="space-y-6">
    {/* Payment progress placeholder */}
    <div className="rounded-2xl border border-slate-200/80 bg-white shadow-xs overflow-hidden">
      <div className="p-6 space-y-4">
        <div className="flex items-center justify-between">
          <Skeleton className="h-4 w-32 rounded-lg" />
          <Skeleton className="h-6 w-12 rounded-full" />
        </div>
        <Skeleton className="h-2.5 w-full rounded-full" />
        <div className="flex justify-between">
          <Skeleton className="h-3 w-40 rounded-md" />
          <Skeleton className="h-3 w-32 rounded-md" />
        </div>
      </div>
    </div>

    {/* KPI tiles */}
    <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6">
      {[...Array(3)].map((_, i) => (
        <div
          key={i}
          className="rounded-2xl border border-slate-200/80 bg-white shadow-xs p-6 space-y-4"
        >
          <Skeleton className="h-12 w-12 rounded-2xl" />
          <Skeleton className="h-8 w-2/3 rounded-lg" />
          <Skeleton className="h-3 w-1/3 rounded-md" />
        </div>
      ))}
    </div>

    {/* Payment history table placeholder */}
    <div className="rounded-2xl border border-slate-200/80 bg-white shadow-xs overflow-hidden">
      <div className="px-6 pt-6">
        <Skeleton className="h-5 w-44 rounded-md" />
      </div>
      <div className="mt-4 border-t border-slate-200/80 bg-slate-50/80 px-6 py-3.5">
        <Skeleton className="h-3 w-full rounded-md" />
      </div>
      <div className="px-6">
        {[...Array(5)].map((_, i) => (
          <div
            key={i}
            className="border-b border-slate-100 last:border-0 py-4 flex items-center gap-4"
          >
            <Skeleton className="h-4 w-20 rounded-md shrink-0" />
            <Skeleton className="h-4 w-40 rounded-md hidden sm:block" />
            <Skeleton className="h-4 w-28 rounded-md hidden md:block" />
            <Skeleton className="h-4 w-24 rounded-md ml-auto shrink-0" />
            <Skeleton className="h-7 w-16 rounded-full shrink-0" />
          </div>
        ))}
      </div>
    </div>
  </div>
);

const toAmount = (value) => {
  const parsed = parseFloat(value);
  return Number.isFinite(parsed) ? parsed : 0;
};

const formatAmount = (value) => toAmount(value).toLocaleString();

const Payments = () => {
  const [payments, setPayments] = useState([]);
  const [totals, setTotals] = useState({ totalPaid: null, totalRemaining: null });
  const [loading, setLoading] = useState(true);
  const [downloading, setDownloading] = useState(null);

  useEffect(() => {
    getPayments()
      .then((res) => {
        const { rows, totalPaid, totalRemaining } = normalizePayments(res.data);
        setPayments(rows);
        setTotals({ totalPaid, totalRemaining });
      })
      .catch(() => toast.error('Failed to load payments'))
      .finally(() => setLoading(false));
  }, []);

  const handleReceipt = async (paymentId) => {
    setDownloading(paymentId);
    try {
      await downloadReceipt(paymentId);
      toast.success('Receipt downloaded');
    } catch (err) {
      toast.error(err.response?.data?.message || 'Receipt unavailable');
    } finally {
      setDownloading(null);
    }
  };

  // The server aggregates the whole history: a payment row is updated in place on
  // every installment, so summing the rows here would only add up the last
  // installment of each reservation.
  const paidAmount = totals.totalPaid ?? 0;
  const remainingAmount = totals.totalRemaining ?? 0;
  const totalAmount = paidAmount + remainingAmount;
  const progressPercent = totalAmount > 0 ? Math.round((paidAmount / totalAmount) * 100) : 0;

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
                My Payments
              </h1>

              {/* Count Badge with Live Pulse Dot */}
              <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-orange-50 border border-orange-200/80 text-orange-700 shadow-2xs">
                <span className="relative flex h-2 w-2">
                  <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-orange-400 opacity-75"></span>
                  <span className="relative inline-flex rounded-full h-2 w-2 bg-orange-500"></span>
                </span>
                {payments.length} {payments.length === 1 ? 'Payment' : 'Payments'}
              </span>
            </div>
          </div>

          <p className="text-sm text-slate-500 mt-1.5 ml-4.5 font-normal leading-relaxed">
            Track your payment history and balances
          </p>
        </div>
      </div>

      {loading ? (
        <PaymentsSkeleton />
      ) : (
        <>
          {/* Payment Progress */}
          {payments.length > 0 && (
            <motion.div
              variants={cardVariants}
              initial="hidden"
              animate="visible"
              whileHover={{ y: -4 }}
            >
              <Card className="p-6 rounded-2xl border-slate-200/80 shadow-xs transition-all duration-200">
                <div className="flex items-center justify-between mb-4 gap-3">
                  <div className="flex items-center gap-3 min-w-0">
                    <div className="w-10 h-10 rounded-xl bg-orange-50 text-orange-600 border border-orange-100 flex items-center justify-center shadow-xs shrink-0">
                      <HiArrowTrendingUp className="w-5 h-5" />
                    </div>
                    <span className="text-sm font-bold text-slate-900 tracking-tight truncate">
                      Payment Progress
                    </span>
                  </div>
                  <span className="text-xs font-extrabold text-orange-600 tabular-nums shrink-0">
                    {progressPercent}%
                  </span>
                </div>

                <Progress value={progressPercent} />

                <div className="flex justify-between mt-3 text-xs text-slate-400">
                  <span className="font-semibold text-slate-500">
                    {paidAmount.toLocaleString()} MAD paid
                  </span>
                  <span className="font-semibold text-slate-500">
                    {totalAmount.toLocaleString()} MAD total
                  </span>
                </div>
              </Card>
            </motion.div>
          )}

          {/* KPI Tiles */}
          <motion.div
            variants={containerVariants}
            initial="hidden"
            animate="visible"
            className="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6"
          >
            <motion.div variants={cardVariants} whileHover={{ y: -4 }}>
              <Card className="p-6 h-full rounded-2xl border-slate-200/80 shadow-xs hover:shadow-xl hover:shadow-slate-200/50 hover:border-slate-300 transition-all duration-200">
                <div className="w-12 h-12 rounded-2xl bg-orange-50 text-orange-600 border border-orange-100 flex items-center justify-center shadow-xs mb-5">
                  <HiBanknotes className="w-6 h-6" />
                </div>
                <div className="flex items-baseline gap-1.5">
                  <span className="text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900">
                    {totalAmount.toLocaleString()}
                  </span>
                  <span className="text-xs font-bold text-orange-600">MAD</span>
                </div>
                <p className="text-xs font-semibold uppercase tracking-wider text-slate-500 mt-1.5">
                  Total Amount
                </p>
              </Card>
            </motion.div>

            <motion.div variants={cardVariants} whileHover={{ y: -4 }}>
              <Card className="p-6 h-full rounded-2xl border-slate-200/80 shadow-xs hover:shadow-xl hover:shadow-slate-200/50 hover:border-slate-300 transition-all duration-200">
                <div className="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 border border-emerald-100 flex items-center justify-center shadow-xs mb-5">
                  <HiCheckCircle className="w-6 h-6" />
                </div>
                <div className="flex items-baseline gap-1.5">
                  <span className="text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900">
                    {paidAmount.toLocaleString()}
                  </span>
                  <span className="text-xs font-bold text-orange-600">MAD</span>
                </div>
                <p className="text-xs font-semibold uppercase tracking-wider text-slate-500 mt-1.5">
                  Paid Amount
                </p>
              </Card>
            </motion.div>

            <motion.div variants={cardVariants} whileHover={{ y: -4 }}>
              <Card className="p-6 h-full rounded-2xl border-slate-200/80 shadow-xs hover:shadow-xl hover:shadow-slate-200/50 hover:border-slate-300 transition-all duration-200">
                <div className="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 border border-amber-100 flex items-center justify-center shadow-xs mb-5">
                  <HiClock className="w-6 h-6" />
                </div>
                <div className="flex items-baseline gap-1.5">
                  <span className="text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900">
                    {remainingAmount.toLocaleString()}
                  </span>
                  <span className="text-xs font-bold text-orange-600">MAD</span>
                </div>
                <p className="text-xs font-semibold uppercase tracking-wider text-slate-500 mt-1.5">
                  Remaining
                </p>
              </Card>
            </motion.div>
          </motion.div>

          {/* Payment History */}
          {payments.length === 0 ? (
            <motion.div
              initial={{ opacity: 0, scale: 0.98 }}
              animate={{ opacity: 1, scale: 1 }}
              transition={{ duration: 0.25 }}
              className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-12 text-center my-8"
            >
              <div className="w-16 h-16 bg-orange-50 rounded-2xl flex items-center justify-center mx-auto mb-4 border border-orange-100 text-orange-600 shadow-xs">
                <HiWallet className="w-8 h-8" />
              </div>
              <h3 className="text-lg font-bold text-slate-900 tracking-tight">
                No Payments Yet
              </h3>
              <p className="text-slate-500 text-sm mt-1.5 max-w-md mx-auto leading-relaxed">
                Your payment history will appear here once you start making payments for your
                contracts.
              </p>
            </motion.div>
          ) : (
            <Card className="rounded-2xl border-slate-200/80 shadow-xs overflow-hidden">
              <div className="flex items-center justify-between gap-3 px-6 pt-6">
                <h2 className="text-lg font-bold tracking-tight text-slate-900">
                  Payment History
                </h2>
                <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-600 ring-1 ring-inset ring-slate-200 backdrop-blur-md shadow-xs">
                  <HiCreditCard className="w-3.5 h-3.5 text-slate-400" />
                  {payments.length} {payments.length === 1 ? 'record' : 'records'}
                </span>
              </div>

              <div className="overflow-x-auto">
                <table className="w-full min-w-[820px] mt-4">
                  <thead className="bg-slate-50/80">
                    <tr className="border-b border-slate-200/80">
                      <th className="text-left text-xs font-semibold uppercase tracking-wider text-slate-500 px-6 py-3.5 whitespace-nowrap">
                        Date
                      </th>
                      <th className="text-left text-xs font-semibold uppercase tracking-wider text-slate-500 px-6 py-3.5 whitespace-nowrap">
                        Amount
                      </th>
                      <th className="text-left text-xs font-semibold uppercase tracking-wider text-slate-500 px-6 py-3.5 whitespace-nowrap">
                        Method
                      </th>
                      <th className="text-left text-xs font-semibold uppercase tracking-wider text-slate-500 px-6 py-3.5 whitespace-nowrap">
                        Status
                      </th>
                      <th className="text-left text-xs font-semibold uppercase tracking-wider text-slate-500 px-6 py-3.5 whitespace-nowrap">
                        Reference
                      </th>
                      <th className="text-right text-xs font-semibold uppercase tracking-wider text-slate-500 px-6 py-3.5 whitespace-nowrap">
                        Receipt
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {payments.map((p) => {
                      const StatusIcon = statusIcons[p.status] || HiClock;
                      return (
                        <tr
                          key={p.id}
                          className="border-b border-slate-100 last:border-0 hover:bg-slate-50/60 transition-colors"
                        >
                          <td className="px-6 py-4 text-sm text-slate-600 whitespace-nowrap">
                            {p.payment_date || p.created_at
                              ? new Date(p.payment_date || p.created_at).toLocaleDateString()
                              : '—'}
                          </td>
                          <td className="px-6 py-4 whitespace-nowrap">
                            <span className="flex items-baseline gap-1">
                              <span className="text-sm font-extrabold text-slate-900">
                                {formatAmount(p.amount)}
                              </span>
                              <span className="text-xs font-bold text-orange-600">MAD</span>
                            </span>
                          </td>
                          <td className="px-6 py-4 text-sm text-slate-600 capitalize whitespace-nowrap">
                            {p.payment_method || p.method || '—'}
                          </td>
                          <td className="px-6 py-4">
                            <span
                              className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold ring-1 ring-inset backdrop-blur-md shadow-xs ${statusColors[p.status] || 'bg-slate-100 text-slate-700 ring-slate-200'}`}
                            >
                              <StatusIcon className="w-3.5 h-3.5" />
                              {statusLabels[p.status] || p.status}
                            </span>
                          </td>
                          <td className="px-6 py-4 text-xs text-slate-500 font-mono">
                            {p.transaction_ref || p.reference || '—'}
                          </td>
                          <td className="px-6 py-4">
                            <div className="flex justify-end">
                              <Button
                                variant="outline"
                                size="sm"
                                disabled={downloading === p.id}
                                onClick={() => handleReceipt(p.id)}
                                className="h-9 px-3 rounded-xl border-slate-200 hover:bg-slate-50 hover:text-slate-900 font-medium text-xs transition-colors cursor-pointer gap-1.5"
                              >
                                {downloading === p.id ? (
                                  <BrandLoader size="small" showText={false} />
                                ) : (
                                  <HiDocumentText className="w-3.5 h-3.5 text-slate-500" />
                                )}
                                PDF
                              </Button>
                            </div>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </Card>
          )}
        </>
      )}
    </motion.div>
  );
};

export default Payments;