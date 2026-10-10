import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import { useAuth } from '../../contexts/AuthContext';
import { getDashboard } from '../../services/ownerService';
import { getImageUrl } from '../../utils/getImageUrl';
import {
  HiHome,
  HiCalendar,
  HiUsers,
  HiCurrencyDollar,
  HiPlus,
  HiSquares2X2,
  HiChevronRight,
  HiArrowRight,
  HiSparkles
} from 'react-icons/hi2';
import { Button } from '../../components/ui/button';
import { Skeleton } from '../../components/ui/skeleton';

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

const StatCard = ({ icon: Icon, label, value, color, link }) => (
  <Link
    to={link}
    className="group block h-full rounded-2xl border border-slate-200/80 bg-white p-5 sm:p-6 shadow-xs hover:shadow-xl hover:shadow-slate-200/50 hover:border-slate-300 transition-all duration-200 active:scale-[0.98] no-underline hover:no-underline cursor-pointer"
  >
    <div className="flex items-start justify-between gap-3 mb-5">
      <div
        className={`w-12 h-12 rounded-2xl border border-orange-100 flex items-center justify-center shadow-xs ${color}`}
      >
        <Icon className="w-5 h-5" />
      </div>
      <span className="w-8 h-8 rounded-full bg-slate-50 border border-slate-100 flex items-center justify-center text-slate-300 group-hover:bg-orange-50 group-hover:border-orange-100 group-hover:text-orange-500 transition-colors duration-200">
        <HiChevronRight className="w-4 h-4" />
      </span>
    </div>

    <p className="text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900">{value}</p>
    <p className="text-xs font-semibold uppercase tracking-wider text-slate-500 mt-1.5">{label}</p>

    <div className="h-1.5 w-12 rounded-full bg-gradient-to-r from-orange-500 to-amber-600 mt-4 opacity-70 group-hover:opacity-100 transition-opacity duration-200" />
  </Link>
);

const QuickAction = ({ icon: Icon, label, link, color }) => (
  <Link
    to={link}
    className="group flex items-center gap-3.5 p-4 bg-white rounded-2xl border border-slate-200/80 shadow-xs hover:shadow-lg hover:shadow-slate-200/50 hover:border-slate-300 transition-all duration-200 active:scale-[0.98] no-underline hover:no-underline cursor-pointer"
  >
    <div
      className={`w-11 h-11 rounded-xl border border-orange-100 flex items-center justify-center shadow-xs shrink-0 ${color}`}
    >
      <Icon className="w-5 h-5" />
    </div>
    <span className="font-semibold text-slate-700 text-sm group-hover:text-orange-600 transition-colors duration-200 flex-1">
      {label}
    </span>
    <HiChevronRight className="w-4 h-4 text-slate-300 group-hover:text-orange-400 group-hover:translate-x-1 transition-all duration-200" />
  </Link>
);

const DashboardSkeleton = () => (
  <div className="space-y-6">
    <div className="flex items-center gap-3">
      <Skeleton className="h-7 w-1.5 rounded-full" />
      <Skeleton className="h-8 w-1/2 rounded-lg" />
    </div>
    <Skeleton className="h-4 w-2/3 rounded-md ml-4.5" />

    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
      {[...Array(4)].map((_, i) => (
        <div
          key={i}
          className="rounded-2xl border border-slate-200/80 bg-white p-0 overflow-hidden shadow-xs space-y-4"
        >
          <div className="p-5 sm:p-6 space-y-4">
            <Skeleton className="w-12 h-12 rounded-2xl" />
            <Skeleton className="h-9 w-1/2 rounded-md" />
            <Skeleton className="h-3 w-2/3 rounded-md" />
            <Skeleton className="h-1.5 w-12 rounded-full" />
          </div>
        </div>
      ))}
    </div>

    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
      {[...Array(3)].map((_, i) => (
        <Skeleton key={i} className="h-[74px] rounded-2xl" />
      ))}
    </div>

    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div className="lg:col-span-2 rounded-2xl border border-slate-200/80 bg-white p-0 overflow-hidden shadow-xs space-y-4">
        <div className="p-5 sm:p-6 pb-0 space-y-4">
          <Skeleton className="h-6 w-1/4 rounded-md" />
          {[...Array(3)].map((_, i) => (
            <Skeleton key={i} className="h-20 w-full rounded-2xl" />
          ))}
        </div>
      </div>
      <div className="space-y-6">
        <div className="rounded-2xl border border-slate-200/80 bg-white p-0 overflow-hidden shadow-xs">
          <div className="p-5 space-y-4">
            <Skeleton className="h-6 w-1/2 rounded-md" />
            {[...Array(3)].map((_, i) => (
              <Skeleton key={i} className="h-14 w-full rounded-xl" />
            ))}
          </div>
        </div>
        <div className="rounded-2xl bg-slate-900 p-6 space-y-4">
          <Skeleton className="w-11 h-11 rounded-xl bg-white/15" />
          <Skeleton className="h-5 w-1/2 rounded-md bg-white/15" />
          <Skeleton className="h-10 w-full rounded-xl bg-white/15" />
        </div>
      </div>
    </div>
  </div>
);

const statusColors = {
  pending: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  accepted: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  rejected: 'bg-rose-50 text-rose-700 ring-rose-600/20',
  paid: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  completed: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  refunded: 'bg-slate-100 text-slate-700 ring-slate-500/20',
};

const statusDots = {
  pending: 'bg-amber-500',
  accepted: 'bg-emerald-500',
  rejected: 'bg-rose-500',
  paid: 'bg-emerald-500',
  completed: 'bg-emerald-500',
  refunded: 'bg-slate-400',
};

const Dashboard = () => {
  const { user } = useAuth();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    getDashboard()
      // Tolerate both a bare payload and a { data: ... } envelope, the way
      // the other owner endpoints are read.
      .then((res) => setData(res.data?.data ?? res.data ?? null))
      .catch(() => { })
      .finally(() => setLoading(false));
  }, []);

  if (loading) {
    return (
      <div className="p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto antialiased selection:bg-orange-500 selection:text-white">
        <DashboardSkeleton />
      </div>
    );
  }

  const stats = data?.stats || {};
  const recentReservations = data?.recent_reservations || [];
  const recentPayments = data?.recent_payments || [];

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
                Welcome back, <span className=' text-orange-500 '>{user?.full_name || 'Owner'}!</span>
              </h1>

              {/* Live Badge */}
              <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-orange-50 border border-orange-200/80 text-orange-700 shadow-2xs">
                <span className="relative flex h-2 w-2">
                  <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-orange-400 opacity-75"></span>
                  <span className="relative inline-flex rounded-full h-2 w-2 bg-orange-500"></span>
                </span>
                {stats.total_properties ?? 0}{' '}
                {stats.total_properties === 1 ? 'Property' : 'Properties'}
              </span>
            </div>
          </div>

          <p className="text-sm text-slate-500 mt-1.5 ml-4.5 font-normal leading-relaxed">
            Here&rsquo;s an overview of your properties and earnings.
          </p>
        </div>
      </div>

      {/* Stat Cards */}
      <motion.div
        variants={containerVariants}
        initial="hidden"
        animate="visible"
        className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6"
      >
        <motion.div variants={cardVariants} whileHover={{ y: -4 }} className="h-full">
          <StatCard
            icon={HiHome}
            label="Total Properties"
            value={stats.total_properties ?? 0}
            color="bg-orange-50 text-orange-600"
            link="/owner/properties"
          />
        </motion.div>
        <motion.div variants={cardVariants} whileHover={{ y: -4 }} className="h-full">
          <StatCard
            icon={HiCalendar}
            label="Pending Reservations"
            value={stats.pending_reservations ?? 0}
            color="bg-amber-50 text-amber-600 border-amber-100"
            link="/owner/reservations"
          />
        </motion.div>
        <motion.div variants={cardVariants} whileHover={{ y: -4 }} className="h-full">
          <StatCard
            icon={HiUsers}
            label="Active Tenants"
            value={stats.active_tenants ?? 0}
            color="bg-emerald-50 text-emerald-600 border-emerald-100"
            link="/owner/contracts"
          />
        </motion.div>
        <motion.div variants={cardVariants} whileHover={{ y: -4 }} className="h-full">
          <StatCard
            icon={HiCurrencyDollar}
            label="Total Revenue"
            value={`${(stats.total_revenue ?? 0).toLocaleString()} MAD`}
            color="bg-slate-900 text-orange-400 border-slate-800"
            link="/owner/payments"
          />
        </motion.div>
      </motion.div>

      {/* Quick Actions */}
      <motion.div
        variants={containerVariants}
        initial="hidden"
        animate="visible"
        className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4"
      >
        <motion.div variants={cardVariants} whileHover={{ y: -4 }}>
          <QuickAction
            icon={HiPlus}
            label="Add Property"
            link="/owner/properties/add"
            color="bg-orange-50 text-orange-600"
          />
        </motion.div>
        <motion.div variants={cardVariants} whileHover={{ y: -4 }}>
          <QuickAction
            icon={HiSquares2X2}
            label="View Properties"
            link="/owner/properties"
            color="bg-slate-100 text-slate-700 border-slate-200"
          />
        </motion.div>
        <motion.div variants={cardVariants} whileHover={{ y: -4 }}>
          <QuickAction
            icon={HiCalendar}
            label="Reservations"
            link="/owner/reservations"
            color="bg-emerald-50 text-emerald-600 border-emerald-100"
          />
        </motion.div>
      </motion.div>

      {/* Content Grid */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Recent Reservations */}
        <div className="lg:col-span-2 rounded-2xl border border-slate-200/80 bg-white shadow-xs hover:shadow-lg hover:shadow-slate-200/50 transition-all duration-200 p-0 overflow-hidden">
          <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 px-5 sm:px-6 pt-5 sm:pt-6 pb-4 border-b border-slate-100">
            <div className="flex items-center gap-2.5">
              <div className="w-9 h-9 rounded-xl bg-orange-50 border border-orange-100 text-orange-600 flex items-center justify-center shrink-0">
                <HiCalendar className="w-4 h-4" />
              </div>
              <h2 className="text-base sm:text-lg font-extrabold tracking-tight text-slate-900 font-display">
                Recent Reservations
              </h2>
            </div>
            <Link
              to="/owner/reservations"
              className="inline-flex items-center gap-1.5 self-start sm:self-auto px-3 py-1.5 rounded-lg text-xs font-semibold text-orange-700 bg-orange-50 border border-orange-200/80 hover:bg-orange-100 transition-colors duration-200 active:scale-[0.98] no-underline hover:no-underline cursor-pointer"
            >
              View all
              <HiArrowRight className="w-4 h-4" />
            </Link>
          </div>

          {recentReservations.length > 0 ? (
            <motion.div
              variants={containerVariants}
              initial="hidden"
              animate="visible"
              className="p-3 sm:p-4 space-y-2"
            >
              {recentReservations.slice(0, 5).map((r) => (
                <motion.div
                  key={r.id}
                  variants={cardVariants}
                  whileHover={{ y: -2 }}
                  className="flex items-center gap-4 p-3 rounded-2xl border border-transparent hover:border-slate-200 hover:bg-slate-50 transition-all duration-200"
                >
                  <div className="w-12 h-12 rounded-xl bg-orange-50 border border-orange-100 flex items-center justify-center overflow-hidden shrink-0">
                    {r.property?.images?.[0] ? (
                      <img
                        src={getImageUrl(r.property.images[0])}
                        alt=""
                        className="w-full h-full object-cover"
                      />
                    ) : (
                      <HiHome className="w-5 h-5 text-orange-500/50" />
                    )}
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="font-semibold text-slate-900 text-sm truncate">{r.property?.title || 'Property'}</p>
                    <p className="text-xs font-medium text-slate-500 mt-0.5 truncate">
                      {r.tenant?.full_name || r.tenant?.name || 'Tenant'}
                    </p>
                  </div>
                  <span
                    className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold capitalize ring-1 ring-inset backdrop-blur-md shadow-xs shrink-0 ${statusColors[r.status] || 'bg-slate-100 text-slate-700 ring-slate-500/20'
                      }`}
                  >
                    <span
                      className={`w-1.5 h-1.5 rounded-full shrink-0 ${statusDots[r.status] || 'bg-slate-400'}`}
                    />
                    {r.status}
                  </span>
                </motion.div>
              ))}
            </motion.div>
          ) : (
            <motion.div
              initial={{ opacity: 0, scale: 0.98 }}
              animate={{ opacity: 1, scale: 1 }}
              transition={{ duration: 0.25 }}
              className="p-10 text-center"
            >
              <div className="w-16 h-16 bg-orange-50 rounded-2xl flex items-center justify-center mx-auto mb-4 border border-orange-100 text-orange-600 shadow-xs">
                <HiCalendar className="w-8 h-8" />
              </div>
              <h3 className="text-lg font-bold text-slate-900 tracking-tight">No reservations yet</h3>
              <p className="text-slate-500 text-sm mt-1.5 max-w-sm mx-auto leading-relaxed">
                Tenant requests will appear here as soon as they come in.
              </p>
              <Button
                asChild
                className="mt-6 h-11 px-5 bg-slate-900 hover:bg-slate-800 text-white font-semibold text-sm rounded-xl shadow-sm hover:shadow-md active:scale-[0.98] transition-all duration-200 border border-slate-800 no-underline hover:no-underline cursor-pointer"
              >
                <Link to="/owner/reservations" className="flex items-center gap-2 no-underline text-white">
                  <HiArrowRight className="w-5 h-5 text-orange-500 stroke-[2.5]" />
                  <span>Browse Reservations</span>
                </Link>
              </Button>
            </motion.div>
          )}
        </div>

        {/* Side Column */}
        <div className="space-y-6">
          {/* Recent Payments */}
          <div className="rounded-2xl border border-slate-200/80 bg-white shadow-xs hover:shadow-lg hover:shadow-slate-200/50 transition-all duration-200 p-0 overflow-hidden">
            <div className="flex items-center gap-2.5 px-5 pt-5 pb-4 border-b border-slate-100">
              <div className="w-9 h-9 rounded-xl bg-emerald-50 border border-emerald-100 text-emerald-600 flex items-center justify-center shrink-0">
                <HiCurrencyDollar className="w-4 h-4" />
              </div>
              <h2 className="text-base sm:text-lg font-extrabold tracking-tight text-slate-900 font-display">
                Recent Payments
              </h2>
            </div>

            {recentPayments.length > 0 ? (
              <motion.div
                variants={containerVariants}
                initial="hidden"
                animate="visible"
                className="p-3 space-y-2"
              >
                {recentPayments.slice(0, 4).map((p, i) => (
                  <motion.div
                    key={p.id ?? i}
                    variants={cardVariants}
                    whileHover={{ y: -2 }}
                    className="flex items-center justify-between gap-3 p-3 rounded-xl border border-transparent hover:border-slate-200 hover:bg-slate-50 transition-all duration-200"
                  >
                    <div className="min-w-0">
                      <p className="text-sm font-semibold text-slate-900 truncate">{p.property?.title || 'Payment'}</p>
                      <p className="text-xs font-medium text-slate-500 mt-0.5">
                        {/* payments carry no created_at; payment_date is the real timestamp */}
                        {p.payment_date
                          ? new Date(p.payment_date).toLocaleDateString()
                          : p.created_at
                            ? new Date(p.created_at).toLocaleDateString()
                            : ''}
                      </p>
                    </div>
                    <span
                      className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold capitalize ring-1 ring-inset backdrop-blur-md shadow-xs shrink-0 ${statusColors[p.status] || 'bg-slate-100 text-slate-700 ring-slate-500/20'
                        }`}
                    >
                      <span
                        className={`w-1.5 h-1.5 rounded-full shrink-0 ${statusDots[p.status] || 'bg-slate-400'}`}
                      />
                      {p.status}
                    </span>
                  </motion.div>
                ))}
              </motion.div>
            ) : (
              <motion.div
                initial={{ opacity: 0, scale: 0.98 }}
                animate={{ opacity: 1, scale: 1 }}
                transition={{ duration: 0.25 }}
                className="p-10 text-center"
              >
                <div className="w-16 h-16 bg-orange-50 rounded-2xl flex items-center justify-center mx-auto mb-4 border border-orange-100 text-orange-600 shadow-xs">
                  <HiCurrencyDollar className="w-8 h-8" />
                </div>
                <h3 className="text-lg font-bold text-slate-900 tracking-tight">No payments yet</h3>
                <p className="text-slate-500 text-sm mt-1.5 max-w-xs mx-auto leading-relaxed">
                  Payments will show up here once tenants start settling their rent.
                </p>
              </motion.div>
            )}
          </div>

          {/* AI Helper Banner */}
          <div className="bg-slate-900 rounded-2xl p-6 text-white border border-slate-800 shadow-xl shadow-slate-900/10 relative overflow-hidden">
            <div className="absolute -top-16 -right-16 w-48 h-48 rounded-full bg-orange-500/10 blur-2xl pointer-events-none" />
            <div className="relative">
              <div className="w-12 h-12 rounded-2xl bg-white/10 border border-white/15 flex items-center justify-center mb-4 text-orange-400">
                <HiSparkles className="w-6 h-6" />
              </div>
              <h3 className="font-extrabold text-lg tracking-tight text-white font-display">AI Helper</h3>
              <p className="text-slate-300 text-sm mt-1.5 mb-5 leading-relaxed">
                Get smart pricing suggestions and property insights powered by AI.
              </p>
              <button className="w-full h-11 px-5 flex items-center justify-center gap-2 bg-orange-500 hover:bg-orange-600 text-white font-semibold text-sm rounded-xl shadow-sm hover:shadow-md active:scale-[0.98] transition-all duration-200 cursor-pointer">
                Open AI Assistant
                <HiArrowRight className="w-4 h-4 stroke-[2.5]" />
              </button>
            </div>
          </div>
        </div>
      </div>
    </motion.div>
  );
};

export default Dashboard;