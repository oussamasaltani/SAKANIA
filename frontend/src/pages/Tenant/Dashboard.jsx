import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import { useAuth } from '../../contexts/AuthContext';
import { getDashboard } from '../../services/tenantService';
import { getConversations } from '../../services/messageService';
import { getImageUrl } from '../../utils/getImageUrl';
import {
  HiHome,
  HiHomeModern,
  HiCalendarDays,
  HiDocumentText,
  HiWallet,
  HiClock,
  HiChatBubbleLeftRight,
  HiPhone,
  HiEnvelope,
  HiChevronRight,
  HiArrowRight,
  HiMagnifyingGlass,
  HiSparkles
} from 'react-icons/hi2';
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

    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      {[...Array(4)].map((_, i) => (
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
  cancelled: 'bg-slate-100 text-slate-600 ring-slate-500/20',
  active: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  completed: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  ended: 'bg-rose-50 text-rose-700 ring-rose-600/20',
};

const statusDots = {
  pending: 'bg-amber-500',
  accepted: 'bg-emerald-500',
  rejected: 'bg-rose-500',
  cancelled: 'bg-slate-400',
  active: 'bg-emerald-500',
  completed: 'bg-emerald-500',
  ended: 'bg-rose-500',
};

const Dashboard = () => {
  const { user } = useAuth();
  const [data, setData] = useState(null);
  const [conversations, setConversations] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    Promise.all([
      getDashboard(),
      getConversations().catch(() => ({ data: { data: [] } })),
    ])
      .then(([dashboardRes, conversationsRes]) => {
        setData(dashboardRes.data);
        setConversations(conversationsRes.data?.data || []);
      })
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

  const rawStats = data?.stats || {};
  const stats = {
    total_reservations: Number(rawStats.total_reservations ?? 0),
    active_contracts: Number(rawStats.active_contracts ?? 0),
    total_payments: Number(rawStats.total_payments ?? 0),
    pending_reservations: Number(rawStats.pending_reservations ?? 0),
  };
  const reservations = data?.recent_reservations || [];
  const properties = data?.featured_properties || [];
  const messages = Array.isArray(data?.recent_messages)
    ? data.recent_messages
    : conversations.map((conversation) => ({
      id: conversation.id,
      sender_name: conversation.counterpart?.full_name,
      message: conversation.last_message?.content,
    }));

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
                Welcome back, <span className=' text-orange-500 '>{user?.full_name || 'Tenant'}!</span>
              </h1>

              {/* Live Badge */}
              <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-orange-50 border border-orange-200/80 text-orange-700 shadow-2xs">
                <span className="relative flex h-2 w-2">
                  <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-orange-400 opacity-75"></span>
                  <span className="relative inline-flex rounded-full h-2 w-2 bg-orange-500"></span>
                </span>
                {stats.total_reservations ?? 0}{' '}
                {stats.total_reservations === 1 ? 'Reservation' : 'Reservations'}
              </span>
            </div>
          </div>

          <p className="text-sm text-slate-500 mt-1.5 ml-4.5 font-normal leading-relaxed">
            Here&rsquo;s what&rsquo;s happening with your rentals.
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
            icon={HiCalendarDays}
            label="My Reservations"
            value={stats.total_reservations ?? 0}
            color="bg-orange-50 text-orange-600"
            link="/tenant/reservations"
          />
        </motion.div>
        <motion.div variants={cardVariants} whileHover={{ y: -4 }} className="h-full">
          <StatCard
            icon={HiDocumentText}
            label="Active Contracts"
            value={stats.active_contracts ?? 0}
            color="bg-emerald-50 text-emerald-600 border-emerald-100"
            link="/tenant/contracts"
          />
        </motion.div>
        <motion.div variants={cardVariants} whileHover={{ y: -4 }} className="h-full">
          <StatCard
            icon={HiWallet}
            label="Total Payments"
            value={`${(stats.total_payments ?? 0).toLocaleString()} MAD`}
            color="bg-slate-900 text-orange-400 border-slate-800"
            link="/tenant/payments"
          />
        </motion.div>
        <motion.div variants={cardVariants} whileHover={{ y: -4 }} className="h-full">
          <StatCard
            icon={HiClock}
            label="Pending"
            value={stats.pending_reservations ?? 0}
            color="bg-amber-50 text-amber-600 border-amber-100"
            link="/tenant/reservations"
          />
        </motion.div>
      </motion.div>

      {/* Quick Actions */}
      <motion.div
        variants={containerVariants}
        initial="hidden"
        animate="visible"
        className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4"
      >
        <motion.div variants={cardVariants} whileHover={{ y: -4 }}>
          <QuickAction
            icon={HiMagnifyingGlass}
            label="Browse Properties"
            link="/properties"
            color="bg-orange-50 text-orange-600"
          />
        </motion.div>
        <motion.div variants={cardVariants} whileHover={{ y: -4 }}>
          <QuickAction
            icon={HiCalendarDays}
            label="My Reservations"
            link="/tenant/reservations"
            color="bg-sky-50 text-sky-600 border-sky-100"
          />
        </motion.div>
        <motion.div variants={cardVariants} whileHover={{ y: -4 }}>
          <QuickAction
            icon={HiDocumentText}
            label="My Contracts"
            link="/tenant/contracts"
            color="bg-emerald-50 text-emerald-600 border-emerald-100"
          />
        </motion.div>
        <motion.div variants={cardVariants} whileHover={{ y: -4 }}>
          <QuickAction
            icon={HiWallet}
            label="My Payments"
            link="/tenant/payments"
            color="bg-violet-50 text-violet-600 border-violet-100"
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
                <HiCalendarDays className="w-4 h-4" />
              </div>
              <h2 className="text-base sm:text-lg font-extrabold tracking-tight text-slate-900 font-display">
                Recent Reservations
              </h2>
            </div>
            <Link
              to="/tenant/reservations"
              className="inline-flex items-center gap-1.5 self-start sm:self-auto px-3 py-1.5 rounded-lg text-xs font-semibold text-orange-700 bg-orange-50 border border-orange-200/80 hover:bg-orange-100 transition-colors duration-200 active:scale-[0.98] no-underline hover:no-underline cursor-pointer"
            >
              View all
              <HiArrowRight className="w-4 h-4" />
            </Link>
          </div>

          {reservations.length > 0 ? (
            <motion.div
              variants={containerVariants}
              initial="hidden"
              animate="visible"
              className="p-3 sm:p-4 space-y-2"
            >
              {reservations.slice(0, 5).map((r) => (
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
                      <HiHomeModern className="w-5 h-5 text-orange-500/50" />
                    )}
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="font-semibold text-slate-900 text-sm truncate">
                      {r.property?.title || 'Property'}
                    </p>
                    <p className="text-xs font-medium text-slate-500 mt-0.5 truncate">
                      {r.created_at ? new Date(r.created_at).toLocaleDateString() : ''}
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
                <HiCalendarDays className="w-8 h-8" />
              </div>
              <h3 className="text-lg font-bold text-slate-900 tracking-tight">No reservations yet</h3>
              <p className="text-slate-500 text-sm mt-1.5 max-w-sm mx-auto leading-relaxed">
                Your reservation requests will show up here as soon as you send one.
              </p>
            </motion.div>
          )}
        </div>

        {/* Side Column */}
        <div className="space-y-6">
          {/* Recent Messages */}
          <div className="rounded-2xl border border-slate-200/80 bg-white shadow-xs hover:shadow-lg hover:shadow-slate-200/50 transition-all duration-200 p-0 overflow-hidden">
            <div className="flex items-center gap-2.5 px-5 pt-5 pb-4 border-b border-slate-100">
              <div className="w-9 h-9 rounded-xl bg-orange-50 border border-orange-100 text-orange-600 flex items-center justify-center shrink-0">
                <HiChatBubbleLeftRight className="w-4 h-4" />
              </div>
              <h2 className="text-base sm:text-lg font-extrabold tracking-tight text-slate-900 font-display">
                Recent Messages
              </h2>
            </div>

            {messages.length > 0 ? (
              <motion.div
                variants={containerVariants}
                initial="hidden"
                animate="visible"
                className="p-3 space-y-2"
              >
                {messages.slice(0, 4).map((msg, i) => (
                  <motion.div
                    key={msg.id ?? i}
                    variants={cardVariants}
                    whileHover={{ y: -2 }}
                    className="flex items-center gap-3 p-3 rounded-xl border border-transparent hover:border-slate-200 hover:bg-slate-50 transition-all duration-200"
                  >
                    <div className="w-9 h-9 rounded-full bg-slate-900 border border-slate-800 flex items-center justify-center shrink-0 shadow-xs">
                      <span className="text-orange-400 text-xs font-bold font-display">
                        {(msg.sender_name || 'U').charAt(0)}
                      </span>
                    </div>
                    <div className="min-w-0 flex-1">
                      <p className="text-sm font-semibold text-slate-900 truncate">
                        {msg.sender_name || 'User'}
                      </p>
                      <p className="text-xs text-slate-500 truncate mt-0.5">
                        {msg.message || msg.content || ''}
                      </p>
                    </div>
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
                  <HiChatBubbleLeftRight className="w-8 h-8" />
                </div>
                <h3 className="text-lg font-bold text-slate-900 tracking-tight">No messages yet</h3>
                <p className="text-slate-500 text-sm mt-1.5 max-w-xs mx-auto leading-relaxed">
                  Open a property to start a conversation with its owner.
                </p>
              </motion.div>
            )}
          </div>

          {/* Support Banner */}
          <div className="bg-slate-900 rounded-2xl p-6 text-white border border-slate-800 shadow-xl shadow-slate-900/10 relative overflow-hidden">
            <div className="absolute -top-16 -right-16 w-48 h-48 rounded-full bg-orange-500/10 blur-2xl pointer-events-none" />
            <div className="relative">
              <div className="w-12 h-12 rounded-2xl bg-white/10 border border-white/15 flex items-center justify-center mb-4 text-orange-400">
                <HiSparkles className="w-6 h-6" />
              </div>
              <h3 className="font-extrabold text-lg tracking-tight text-white font-display">Need Help?</h3>
              <p className="text-slate-300 text-sm mt-1.5 mb-5 leading-relaxed">
                Our support team is available 24/7 to answer your questions.
              </p>
              <div className="space-y-2.5">
                <a
                  href="tel:+212600000000"
                  className="flex items-center gap-2.5 h-11 px-4 rounded-xl bg-white/5 border border-white/10 text-slate-200 hover:bg-white/10 hover:text-white text-sm font-medium transition-colors duration-200 active:scale-[0.98] no-underline hover:no-underline"
                >
                  <HiPhone className="w-4 h-4 text-orange-400 shrink-0" />
                  +212 6 00 00 00 00
                </a>
                <a
                  href="mailto:support@sakania.ma"
                  className="flex items-center gap-2.5 h-11 px-4 rounded-xl bg-white/5 border border-white/10 text-slate-200 hover:bg-white/10 hover:text-white text-sm font-medium transition-colors duration-200 active:scale-[0.98] no-underline hover:no-underline"
                >
                  <HiEnvelope className="w-4 h-4 text-orange-400 shrink-0" />
                  support@sakania.ma
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Featured Properties */}
      {properties.length > 0 && (
        <div className="rounded-2xl border border-slate-200/80 bg-white shadow-xs hover:shadow-lg hover:shadow-slate-200/50 transition-all duration-200 p-0 overflow-hidden">
          <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 px-5 sm:px-6 pt-5 sm:pt-6 pb-4 border-b border-slate-100">
            <div className="flex items-center gap-2.5">
              <div className="w-9 h-9 rounded-xl bg-orange-50 border border-orange-100 text-orange-600 flex items-center justify-center shrink-0">
                <HiHome className="w-4 h-4" />
              </div>
              <h2 className="text-base sm:text-lg font-extrabold tracking-tight text-slate-900 font-display">
                Featured Properties
              </h2>
            </div>
            <Link
              to="/properties"
              className="inline-flex items-center gap-1.5 self-start sm:self-auto px-3 py-1.5 rounded-lg text-xs font-semibold text-orange-700 bg-orange-50 border border-orange-200/80 hover:bg-orange-100 transition-colors duration-200 active:scale-[0.98] no-underline hover:no-underline cursor-pointer"
            >
              Browse all
              <HiArrowRight className="w-4 h-4" />
            </Link>
          </div>

          <motion.div
            variants={containerVariants}
            initial="hidden"
            animate="visible"
            className="p-3 sm:p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4"
          >
            {properties.slice(0, 6).map((p) => (
              <motion.div key={p.id} variants={cardVariants} whileHover={{ y: -4 }}>
                <Link
                  to={`/properties/${p.id}`}
                  className="group flex flex-col h-full rounded-2xl border border-slate-200/80 bg-white overflow-hidden shadow-xs hover:shadow-xl hover:shadow-slate-200/50 hover:border-slate-300 transition-all duration-200 active:scale-[0.98] no-underline hover:no-underline cursor-pointer"
                >
                  <div className="h-44 bg-slate-50 border-b border-slate-200/80 flex items-center justify-center overflow-hidden">
                    {p.images?.[0] ? (
                      <img
                        src={getImageUrl(p.images[0])}
                        alt={p.title}
                        className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500 ease-out"
                      />
                    ) : (
                      <HiHomeModern className="w-12 h-12 text-slate-300 stroke-[1.5]" />
                    )}
                  </div>
                  <div className="p-4 flex-1 flex flex-col justify-end space-y-2">
                    <h3 className="font-bold text-slate-900 group-hover:text-orange-600 transition-colors duration-200 text-sm truncate tracking-tight">
                      {p.title}
                    </h3>
                    <div className="flex items-baseline gap-1 pt-2 border-t border-slate-100">
                      <span className="text-base font-extrabold text-slate-900 tracking-tight">
                        {p.price_per_month?.toLocaleString()}
                      </span>
                      <span className="text-xs font-bold text-orange-600">MAD</span>
                      <span className="text-xs text-slate-400 font-normal">/mo</span>
                    </div>
                  </div>
                </Link>
              </motion.div>
            ))}
          </motion.div>
        </div>
      )}
    </motion.div>
  );
};

export default Dashboard;