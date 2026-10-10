import { useState, useEffect } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import { getContract } from '../../services/tenantService';
import { downloadContract } from '../../services/paymentService';
import toast from 'react-hot-toast';
import BrandLoader from '../../components/common/BrandLoader';
import {
  HiArrowLeft,
  HiArrowDownTray,
  HiArrowPath,
  HiCalendarDays,
  HiUser,
  HiHome,
  HiBanknotes,
  HiDocumentText
} from 'react-icons/hi2';
import { Button } from '../../components/ui/button';
import { Progress } from '../../components/ui/progress';
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

const ContractDetailsSkeleton = () => (
  <div className="space-y-6">
    <div className="flex items-center gap-4">
      <Skeleton className="h-9 w-9 rounded-xl shrink-0" />
      <div className="space-y-2">
        <Skeleton className="h-7 w-48 rounded-md" />
        <Skeleton className="h-3.5 w-32 rounded-md" />
      </div>
    </div>
    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div className="lg:col-span-2 space-y-6">
        <div className="rounded-2xl border border-slate-200/80 bg-white overflow-hidden shadow-xs">
          <div className="p-5 space-y-5">
            <div className="flex items-center gap-3">
              <Skeleton className="h-10 w-10 rounded-xl shrink-0" />
              <Skeleton className="h-4 w-40 rounded-md" />
              <Skeleton className="h-6 w-20 rounded-full ml-auto shrink-0" />
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-5 pt-4 border-t border-slate-100">
              {[...Array(6)].map((_, i) => (
                <div key={i} className="flex items-start gap-3">
                  <Skeleton className="h-10 w-10 rounded-xl shrink-0" />
                  <div className="flex-1 space-y-2 pt-1">
                    <Skeleton className="h-3 w-20 rounded-md" />
                    <Skeleton className="h-4 w-2/3 rounded-md" />
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
        <div className="rounded-2xl border border-slate-200/80 bg-white overflow-hidden shadow-xs p-5 space-y-3">
          <Skeleton className="h-4 w-40 rounded-md" />
          <Skeleton className="h-3.5 w-full rounded-md" />
          <Skeleton className="h-3.5 w-5/6 rounded-md" />
        </div>
      </div>
      <div className="space-y-6">
        <div className="rounded-2xl border border-slate-200/80 bg-white overflow-hidden shadow-xs">
          <div className="p-5 space-y-5">
            <div className="flex items-center gap-3">
              <Skeleton className="h-10 w-10 rounded-xl shrink-0" />
              <Skeleton className="h-4 w-32 rounded-md" />
            </div>
            <div className="space-y-3 pt-4 border-t border-slate-100">
              {[...Array(3)].map((_, i) => (
                <div key={i} className="flex items-center justify-between gap-4">
                  <Skeleton className="h-3.5 w-24 rounded-md" />
                  <Skeleton className="h-3.5 w-20 rounded-md" />
                </div>
              ))}
              <Skeleton className="h-2.5 w-full rounded-full" />
            </div>
          </div>
        </div>
        <Skeleton className="h-11 w-full rounded-xl" />
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

const ContractDetails = () => {
  const { id } = useParams();
  const navigate = useNavigate();
  const [contract, setContract] = useState(null);
  const [loading, setLoading] = useState(true);
  const [downloading, setDownloading] = useState(false);

  const handleDownload = async () => {
    setDownloading(true);
    try {
      await downloadContract(id);
      toast.success('Contract downloaded');
    } catch {
      toast.error('Failed to download the contract');
    } finally {
      setDownloading(false);
    }
  };

  useEffect(() => {
    getContract(id)
      .then((res) => setContract(res.data?.data || res.data))
      .catch(() => {
        toast.error('Failed to load contract details');
        navigate('/tenant/contracts');
      })
      .finally(() => setLoading(false));
  }, [id, navigate]);

  if (loading) {
    return (
      <div className="p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto antialiased">
        <ContractDetailsSkeleton />
      </div>
    );
  }

  if (!contract) return null;

  return (
    <motion.div
      initial={{ opacity: 0, y: 12 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.35, ease: 'easeOut' }}
      className="p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto space-y-6 antialiased selection:bg-orange-500 selection:text-white"
    >
      {/* Header Section */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-2 border-b border-slate-200/60">
        <div className="flex items-center gap-4">
          <Button
            variant="ghost"
            size="icon"
            onClick={() => navigate('/tenant/contracts')}
            aria-label="Back to contracts"
            className="h-10 w-10 shrink-0 rounded-xl text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors cursor-pointer"
          >
            <HiArrowLeft className="w-5 h-5" />
          </Button>

          <div className="relative">
            <div className="flex items-center gap-3">
              {/* Orange Gradient Accent Bar */}
              <div className="h-7 w-1.5 rounded-full bg-gradient-to-b from-orange-500 to-amber-500 shadow-sm" />

              <div className="flex items-center gap-3 flex-wrap">
                <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900 font-display">
                  Contract Details
                </h1>

                <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-orange-50 border border-orange-200/80 text-orange-700 shadow-2xs">
                  <span className="relative flex h-2 w-2">
                    <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-orange-400 opacity-75"></span>
                    <span className="relative inline-flex rounded-full h-2 w-2 bg-orange-500"></span>
                  </span>
                  #{contract.id}
                </span>
              </div>
            </div>

            <p className="text-sm text-slate-500 mt-1.5 ml-4.5 font-normal leading-relaxed">
              Review the terms, timeline, and payment progress for this agreement.
            </p>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Main Column */}
        <div className="lg:col-span-2 space-y-6">
          <motion.section
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.3, ease: 'easeOut' }}
            className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-5"
          >
            <div className="flex items-center justify-between gap-3 mb-5 pb-4 border-b border-slate-100">
              <div className="flex items-center gap-3 min-w-0">
                <div className="w-10 h-10 rounded-xl bg-orange-50 text-orange-600 border border-orange-100 flex items-center justify-center shrink-0">
                  <HiDocumentText className="w-5 h-5" />
                </div>
                <h2 className="text-base font-bold text-slate-900 tracking-tight">
                  Contract Information
                </h2>
              </div>
              <span
                className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold ring-1 ring-inset backdrop-blur-md shadow-xs shrink-0 ${statusColors[contract.status] || 'bg-slate-100 text-slate-700 ring-slate-200'}`}
              >
                <span className={`w-1.5 h-1.5 rounded-full ${statusDots[contract.status] || 'bg-slate-400'}`} />
                {statusLabels[contract.status] || contract.status}
              </span>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
              <InfoRow icon={HiHome} label="Property" value={contract.property?.title} />
              <InfoRow icon={HiUser} label="Owner" value={contract.owner?.full_name || contract.owner?.name} />
              <InfoRow icon={HiCalendarDays} label="Start Date" value={contract.start_date ? new Date(contract.start_date).toLocaleDateString() : null} />
              <InfoRow icon={HiCalendarDays} label="End Date" value={contract.end_date ? new Date(contract.end_date).toLocaleDateString() : 'Ongoing'} />
              {contract.monthly_rent && (
                <InfoRow icon={HiBanknotes} label="Monthly Rent" value={`${contract.monthly_rent.toLocaleString()} MAD`} />
              )}
              {contract.duration && (
                <InfoRow icon={HiCalendarDays} label="Duration" value={`${contract.duration} months`} />
              )}
            </div>
          </motion.section>

          {contract.terms && (
            <motion.section
              initial={{ opacity: 0, y: 12 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.3, ease: 'easeOut', delay: 0.06 }}
              className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-5"
            >
              <div className="flex items-center gap-3 mb-4 pb-4 border-b border-slate-100">
                <div className="w-10 h-10 rounded-xl bg-orange-50 text-orange-600 border border-orange-100 flex items-center justify-center shrink-0">
                  <HiDocumentText className="w-5 h-5" />
                </div>
                <h2 className="text-base font-bold text-slate-900 tracking-tight">
                  Terms &amp; Conditions
                </h2>
              </div>
              <p className="text-sm text-slate-600 leading-relaxed whitespace-pre-wrap">
                {contract.terms}
              </p>
            </motion.section>
          )}
        </div>

        {/* Sidebar Column */}
        <div className="space-y-6">
          <motion.section
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.3, ease: 'easeOut', delay: 0.12 }}
            className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-5"
          >
            <div className="flex items-center gap-3 mb-5 pb-4 border-b border-slate-100">
              <div className="w-10 h-10 rounded-xl bg-orange-50 text-orange-600 border border-orange-100 flex items-center justify-center shrink-0">
                <HiBanknotes className="w-5 h-5" />
              </div>
              <h2 className="text-base font-bold text-slate-900 tracking-tight">
                Payment Status
              </h2>
            </div>
            <div className="space-y-3">
              <div className="flex items-center justify-between gap-3 text-sm py-1">
                <span className="text-xs font-semibold uppercase tracking-wider text-slate-500">
                  Total Amount
                </span>
                <span className="text-sm font-semibold text-slate-900">
                  {(contract.total_amount || contract.monthly_rent * (contract.duration || 1) || 0).toLocaleString()} MAD
                </span>
              </div>
              <div className="flex items-center justify-between gap-3 text-sm py-1">
                <span className="text-xs font-semibold uppercase tracking-wider text-slate-500">
                  Paid Amount
                </span>
                <span className="text-sm font-semibold text-emerald-600">
                  {(contract.paid_amount || 0).toLocaleString()} MAD
                </span>
              </div>
              <div className="flex items-center justify-between gap-3 text-sm py-1">
                <span className="text-xs font-semibold uppercase tracking-wider text-slate-500">
                  Remaining
                </span>
                <span className="text-sm font-semibold text-rose-600">
                  {((contract.total_amount || contract.monthly_rent * (contract.duration || 1) || 0) - (contract.paid_amount || 0)).toLocaleString()} MAD
                </span>
              </div>
              {contract.total_amount > 0 && (
                <div className="mt-4 pt-4 border-t border-slate-100">
                  <div className="flex items-center justify-between gap-3 mb-2">
                    <span className="text-sm font-semibold text-slate-900">Paid to date</span>
                    <span className="text-xs font-semibold text-orange-600">
                      {Math.round(((contract.paid_amount || 0) / (contract.total_amount || 1)) * 100)}%
                    </span>
                  </div>
                  <Progress value={Math.min(((contract.paid_amount || 0) / (contract.total_amount || 1)) * 100, 100)} />
                  <p className="text-xs text-slate-400 mt-1.5 text-right">
                    {Math.round(((contract.paid_amount || 0) / (contract.total_amount || 1)) * 100)}% paid
                  </p>
                </div>
              )}
            </div>
          </motion.section>

          <motion.div
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.3, ease: 'easeOut', delay: 0.18 }}
            className="space-y-3"
          >
            <Button
              onClick={handleDownload}
              disabled={downloading}
              className="w-full h-11 px-5 bg-slate-900 hover:bg-slate-800 text-white font-semibold text-sm rounded-xl shadow-sm hover:shadow-md active:scale-[0.98] transition-all duration-200 border border-slate-800 no-underline hover:no-underline cursor-pointer"
            >
              {downloading ? (
                <div className="flex items-center justify-center gap-2">
                  <BrandLoader size="small" showText={false} />
                  <span>Preparing PDF...</span>
                </div>
              ) : (
                <>
                  <HiArrowDownTray className="w-5 h-5 text-orange-500 stroke-[2.5]" />
                  <span>Download PDF</span>
                </>
              )}
            </Button>

            <Button
              asChild
              variant="outline"
              className="w-full h-11 rounded-xl border-slate-200 hover:bg-slate-50 hover:text-slate-900 font-medium text-sm transition-colors cursor-pointer no-underline hover:no-underline"
            >
              <Link to="/tenant/contracts" className="no-underline hover:no-underline">
                <HiArrowPath className="w-4 h-4 text-slate-500" />
                Back to Contracts
              </Link>
            </Button>
          </motion.div>
        </div>
      </div>
    </motion.div>
  );
};

export default ContractDetails;