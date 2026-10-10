import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import { getContracts } from '../../services/ownerService';
import toast from 'react-hot-toast';
import {
  HiDocumentText,
  HiUser,
  HiCalendarDays,
  HiCurrencyDollar,
  HiChevronRight
} from 'react-icons/hi2';
import { Skeleton } from '../../components/ui/skeleton';

const statusColors = {
  active: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  completed: 'bg-sky-50 text-sky-700 ring-sky-600/20',
  ended: 'bg-rose-50 text-rose-700 ring-rose-600/20',
  pending: 'bg-amber-50 text-amber-700 ring-amber-600/20',
};

const statusDots = {
  active: 'bg-emerald-500',
  completed: 'bg-sky-500',
  ended: 'bg-rose-500',
  pending: 'bg-amber-500',
};

const statusLabels = {
  active: 'Active',
  completed: 'Completed',
  ended: 'Ended',
  pending: 'Pending',
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

const ContractsSkeleton = () => (
  <div className="space-y-4">
    {[...Array(3)].map((_, i) => (
      <div
        key={i}
        className="rounded-2xl border border-slate-200/80 bg-white overflow-hidden shadow-xs"
      >
        <div className="p-5 space-y-4">
          <div className="flex items-center gap-3">
            <Skeleton className="h-11 w-11 rounded-xl shrink-0" />
            <div className="flex-1 space-y-2">
              <Skeleton className="h-4 w-1/3 rounded-md" />
              <Skeleton className="h-3 w-1/4 rounded-md" />
            </div>
            <Skeleton className="h-6 w-20 rounded-full shrink-0" />
          </div>
          <div className="pt-3 border-t border-slate-100 flex flex-wrap items-center gap-4">
            <Skeleton className="h-4 w-48 rounded-md" />
            <Skeleton className="h-4 w-28 rounded-md" />
          </div>
        </div>
      </div>
    ))}
  </div>
);

const Contracts = () => {
  const [contracts, setContracts] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    getContracts()
      .then((res) => setContracts(res.data?.data || res.data || []))
      .catch(() => toast.error('Failed to load contracts'))
      .finally(() => setLoading(false));
  }, []);

  const stats = {
    total: contracts.length,
    active: contracts.filter((c) => c.status === 'active').length,
    completed: contracts.filter((c) => c.status === 'completed').length,
    ended: contracts.filter((c) => c.status === 'ended').length,
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
                My Contracts
              </h1>

              {/* Count Badge with Live Pulse Dot */}
              <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-orange-50 border border-orange-200/80 text-orange-700 shadow-2xs">
                <span className="relative flex h-2 w-2">
                  <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-orange-400 opacity-75"></span>
                  <span className="relative inline-flex rounded-full h-2 w-2 bg-orange-500"></span>
                </span>
                {contracts.length} {contracts.length === 1 ? 'Contract' : 'Contracts'}
              </span>
            </div>
          </div>

          <p className="text-sm text-slate-500 mt-1.5 ml-4.5 font-normal leading-relaxed">
            View and manage your rental contracts
          </p>
        </div>
      </div>

      {/* Stats Row */}
      {!loading && contracts.length > 0 && (
        <motion.div
          variants={containerVariants}
          initial="hidden"
          animate="visible"
          className="grid grid-cols-2 sm:grid-cols-4 gap-3"
        >
          {[
            { label: 'Total', value: stats.total, color: 'bg-slate-100 text-slate-700 ring-slate-200/70' },
            { label: 'Active', value: stats.active, color: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' },
            { label: 'Completed', value: stats.completed, color: 'bg-sky-50 text-sky-700 ring-sky-600/20' },
            { label: 'Ended', value: stats.ended, color: 'bg-rose-50 text-rose-700 ring-rose-600/20' },
          ].map((s) => (
            <motion.div
              key={s.label}
              variants={cardVariants}
              whileHover={{ y: -4 }}
              className={`rounded-xl p-4 ring-1 ring-inset shadow-xs transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md ${s.color}`}
            >
              <p className="text-2xl font-extrabold tracking-tight">{s.value}</p>
              <p className="text-xs font-semibold uppercase tracking-wider opacity-75 mt-1">{s.label}</p>
            </motion.div>
          ))}
        </motion.div>
      )}

      {/* Content Rendering */}
      {loading ? (
        <ContractsSkeleton />
      ) : contracts.length === 0 ? (
        <motion.div
          initial={{ opacity: 0, scale: 0.98 }}
          animate={{ opacity: 1, scale: 1 }}
          transition={{ duration: 0.25 }}
          className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-12 text-center my-8"
        >
          <div className="w-16 h-16 bg-orange-50 rounded-2xl flex items-center justify-center mx-auto mb-4 border border-orange-100 text-orange-600 shadow-xs">
            <HiDocumentText className="w-8 h-8" />
          </div>
          <h3 className="text-lg font-bold text-slate-900 tracking-tight">No Contracts Yet</h3>
          <p className="text-slate-500 text-sm mt-1.5 max-w-md mx-auto leading-relaxed">
            When you accept a reservation, a contract will be created automatically.
          </p>
        </motion.div>
      ) : (
        <motion.div
          variants={containerVariants}
          initial="hidden"
          animate="visible"
          className="space-y-4"
        >
          {contracts.map((c) => (
            <motion.div key={c.id} variants={cardVariants} whileHover={{ y: -4 }}>
              <Link
                to={`/owner/contracts/${c.id}`}
                className="block bg-white rounded-2xl border border-slate-200/80 shadow-xs hover:shadow-xl hover:shadow-slate-200/50 hover:border-slate-300 transition-all duration-200 p-5 group no-underline hover:no-underline active:scale-[0.98]"
              >
                <div className="flex items-start justify-between gap-4">
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-3">
                      <div className="w-11 h-11 rounded-xl bg-orange-50 text-orange-600 border border-orange-100 flex items-center justify-center shrink-0 transition-colors group-hover:bg-orange-100/70">
                        <HiDocumentText className="w-5 h-5" />
                      </div>
                      <div className="min-w-0">
                        <h3 className="font-bold text-slate-900 group-hover:text-orange-600 transition-colors truncate text-base tracking-tight">
                          {c.property?.title || 'Property'}
                        </h3>
                        {c.tenant && (
                          <p className="text-sm text-slate-500 flex items-center gap-1.5 mt-1">
                            <HiUser className="w-3.5 h-3.5 text-slate-400 shrink-0" />
                            <span className="truncate font-medium">
                              {c.tenant.full_name || c.tenant.name || 'Tenant'}
                            </span>
                          </p>
                        )}
                      </div>
                    </div>
                    <div className="flex flex-wrap gap-4 mt-4 ml-14 text-sm text-slate-500">
                      {c.start_date && (
                        <span className="flex items-center gap-1.5 font-medium">
                          <HiCalendarDays className="w-4 h-4 text-slate-400 shrink-0" />
                          {new Date(c.start_date).toLocaleDateString()} – {c.end_date ? new Date(c.end_date).toLocaleDateString() : 'Ongoing'}
                        </span>
                      )}
                      {c.monthly_rent && (
                        <span className="inline-flex items-center gap-1.5 font-semibold text-orange-600">
                          <HiCurrencyDollar className="w-4 h-4 shrink-0" />
                          {c.monthly_rent.toLocaleString()} MAD/mo
                        </span>
                      )}
                    </div>
                  </div>
                  <div className="flex items-center gap-3 shrink-0">
                    <span
                      className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold ring-1 ring-inset backdrop-blur-md shadow-xs ${statusColors[c.status] || 'bg-slate-100 text-slate-700 ring-slate-200'}`}
                    >
                      <span className={`w-1.5 h-1.5 rounded-full ${statusDots[c.status] || 'bg-slate-400'}`} />
                      {statusLabels[c.status] || c.status}
                    </span>
                    <span className="w-9 h-9 rounded-xl bg-slate-50 border border-slate-200/70 text-slate-400 flex items-center justify-center transition-all duration-200 group-hover:bg-orange-50 group-hover:border-orange-100 group-hover:text-orange-600 group-hover:translate-x-1">
                      <HiChevronRight className="w-4 h-4" />
                    </span>
                  </div>
                </div>
              </Link>
            </motion.div>
          ))}
        </motion.div>
      )}
    </motion.div>
  );
};

export default Contracts;