import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import { getContracts } from '../../services/tenantService';
import toast from 'react-hot-toast';
import {
  HiDocumentText,
  HiUser,
  HiCalendarDays,
  HiChevronRight,
  HiCheckCircle,
  HiClock,
  HiXCircle,
  HiArrowRight
} from 'react-icons/hi2';
import { Button } from '../../components/ui/button';


const statusColors = {
  active: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  completed: 'bg-blue-50 text-blue-700 ring-blue-600/20',
  ended: 'bg-rose-50 text-rose-700 ring-rose-600/20',
  pending: 'bg-amber-50 text-amber-700 ring-amber-600/20',
};

const statusDots = {
  active: 'bg-emerald-500',
  completed: 'bg-blue-500',
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
      staggerChildren: 0.07,
      delayChildren: 0.05,
    },
  },
};

const itemVariants = {
  hidden: { opacity: 0, y: 16 },
  visible: {
    opacity: 1,
    y: 0,
    transition: { type: 'spring', stiffness: 280, damping: 24 },
  },
};

import BrandLoader from '../../components/common/BrandLoader';

const MyContractsSkeleton = () => (
  <div className="flex items-center justify-center py-16">
    <BrandLoader size="default" showText={true} text="Chargement en cours..." />
  </div>
);

const MyContracts = () => {
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
      className="p-4 sm:p-6 lg:p-8 max-w-5xl mx-auto space-y-6 antialiased selection:bg-orange-500 selection:text-white"
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
                {stats.total} Total
              </span>
            </div>
          </div>

          <p className="text-sm text-slate-500 mt-1.5 ml-4.5 font-normal leading-relaxed">
            View, track, and manage all your property rental agreements
          </p>
        </div>
      </div>

      {/* Stats Cards Grid */}
      {!loading && contracts.length > 0 && (
        <motion.div
          variants={containerVariants}
          initial="hidden"
          animate="visible"
          className="grid grid-cols-2 sm:grid-cols-4 gap-3.5"
        >
          {[
            {
              label: 'Total',
              value: stats.total,
              color: 'bg-slate-50 text-slate-800 ring-slate-200/80',
              icon: HiDocumentText,
              iconColor: 'text-slate-500'
            },
            {
              label: 'Active',
              value: stats.active,
              color: 'bg-emerald-50/80 text-emerald-800 ring-emerald-200/80',
              icon: HiCheckCircle,
              iconColor: 'text-emerald-600'
            },
            {
              label: 'Completed',
              value: stats.completed,
              color: 'bg-blue-50/80 text-blue-800 ring-blue-200/80',
              icon: HiClock,
              iconColor: 'text-blue-600'
            },
            {
              label: 'Ended',
              value: stats.ended,
              color: 'bg-rose-50/80 text-rose-800 ring-rose-200/80',
              icon: HiXCircle,
              iconColor: 'text-rose-600'
            },
          ].map((s) => {
            const Icon = s.icon;
            return (
              <motion.div
                key={s.label}
                variants={itemVariants}
                whileHover={{ y: -2 }}
                className={`rounded-2xl p-4 ring-1 ring-inset ${s.color} transition-all duration-200 shadow-2xs flex flex-col justify-between hover:shadow-lg hover:shadow-slate-200/50`}
              >
                <div className="flex items-center justify-between">
                  <span className="text-xs font-semibold uppercase tracking-wider opacity-70">
                    {s.label}
                  </span>
                  <Icon className={`w-4 h-4 ${s.iconColor}`} />
                </div>
                <p className="text-2xl sm:text-3xl font-extrabold tracking-tight mt-2">
                  {s.value}
                </p>
              </motion.div>
            );
          })}
        </motion.div>
      )}

      {/* Content Rendering */}
      {loading ? (
        <MyContractsSkeleton />
      ) : contracts.length === 0 ? (
        <motion.div
          initial={{ opacity: 0, scale: 0.98 }}
          animate={{ opacity: 1, scale: 1 }}
          transition={{ duration: 0.25 }}
          className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-12 text-center my-6"
        >
          <div className="w-16 h-16 bg-orange-50 rounded-2xl flex items-center justify-center mx-auto mb-4 border border-orange-100 text-orange-600 shadow-xs">
            <HiDocumentText className="w-8 h-8" />
          </div>
          <h3 className="text-lg font-bold text-slate-900 tracking-tight">No Contracts Yet</h3>
          <p className="text-slate-500 text-sm mt-1.5 max-w-sm mx-auto leading-relaxed">
            You don't have any contracts yet. Once your reservation is accepted by the owner, a formal contract will be created.
          </p>
          <Button
            asChild
            className="mt-6 h-11 px-6 bg-slate-900 hover:bg-slate-800 text-white font-semibold text-sm rounded-xl shadow-sm hover:shadow-md active:scale-[0.98] transition-all duration-200 border border-slate-800 no-underline hover:no-underline cursor-pointer"
          >
            <Link to="/properties" className="flex items-center gap-2 no-underline text-white">
              <span>Browse Properties</span>
              <HiArrowRight className="w-4 h-4 stroke-[2]" />
            </Link>
          </Button>
        </motion.div>
      ) : (
        <motion.div
          variants={containerVariants}
          initial="hidden"
          animate="visible"
          className="space-y-3.5"
        >
          {contracts.map((c) => (
            <motion.div key={c.id} variants={itemVariants}>
              <Link
                to={`/tenant/contracts/${c.id}`}
                className="group block bg-white rounded-2xl border border-slate-200/80 p-5 sm:p-6 shadow-xs hover:border-slate-300 hover:shadow-md transition-all duration-200 no-underline hover:no-underline cursor-pointer"
              >
                <div className="flex items-start justify-between gap-4">
                  <div className="flex-1 min-w-0">
                    {/* Header Row: Document Icon, Title & Owner */}
                    <div className="flex items-center gap-3.5">
                      <div className="w-12 h-12 rounded-2xl bg-orange-50 border border-orange-100/80 text-orange-600 flex items-center justify-center shrink-0 shadow-2xs">
                        <HiDocumentText className="w-6 h-6" />
                      </div>
                      <div className="min-w-0 flex-1">
                        <h3 className="font-bold text-slate-900 group-hover:text-orange-600 transition-colors duration-200 truncate text-base tracking-tight">
                          {c.property?.title || 'Property'}
                        </h3>
                        {c.owner && (
                          <p className="text-xs font-medium text-slate-500 flex items-center gap-1.5 mt-1">
                            <HiUser className="w-3.5 h-3.5 text-slate-400 shrink-0" />
                            <span className="truncate">{c.owner.full_name || c.owner.name || 'Owner'}</span>
                          </p>
                        )}
                      </div>
                    </div>

                    {/* Meta Row: Contract Dates & Rent Amount */}
                    <div className="flex flex-wrap items-center gap-y-2 gap-x-4 mt-4 sm:ml-[62px] text-xs font-medium text-slate-500 pt-3 border-t border-slate-100 sm:border-0 sm:pt-0">
                      {c.start_date && (
                        <span className="flex items-center gap-1.5 text-slate-600 bg-slate-50 px-2.5 py-1 rounded-lg border border-slate-200/60">
                          <HiCalendarDays className="w-4 h-4 text-slate-400 shrink-0" />
                          <span>
                            {new Date(c.start_date).toLocaleDateString()} – {c.end_date ? new Date(c.end_date).toLocaleDateString() : 'Ongoing'}
                          </span>
                        </span>
                      )}
                      {c.monthly_rent && (
                        <span className="flex items-baseline gap-1 text-slate-900 font-bold bg-orange-50/60 px-2.5 py-1 rounded-lg border border-orange-200/50">
                          <span className="text-sm font-extrabold text-slate-900">{c.monthly_rent.toLocaleString()}</span>
                          <span className="text-orange-600 text-xs font-extrabold">MAD</span>
                          <span className="text-slate-400 font-normal text-[11px]">/mo</span>
                        </span>
                      )}
                    </div>
                  </div>

                  {/* Status Badge & Arrow Action */}
                  <div className="flex items-center gap-3 shrink-0 self-center">
                    <span
                      className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold ring-1 ring-inset backdrop-blur-md shadow-2xs ${statusColors[c.status] || 'bg-slate-100 text-slate-600 ring-slate-200'
                        }`}
                    >
                      <span className={`w-1.5 h-1.5 rounded-full ${statusDots[c.status] || 'bg-slate-400'}`} />
                      {statusLabels[c.status] || c.status}
                    </span>

                    <div className="w-8 h-8 rounded-xl bg-slate-50 text-slate-400 group-hover:bg-slate-100 group-hover:text-slate-700 flex items-center justify-center transition-all duration-200">
                      <HiChevronRight className="w-4 h-4 group-hover:translate-x-0.5 transition-transform" />
                    </div>
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

export default MyContracts;