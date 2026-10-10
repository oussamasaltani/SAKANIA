import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import DashboardLayout from '../../components/layouts/DashboardLayout';
import { getProperties, approveProperty, rejectProperty, deleteProperty } from '../../services/adminService';
import { getImageUrl } from '../../utils/getImageUrl';
import toast from 'react-hot-toast';
import { motion, AnimatePresence } from 'framer-motion';
import BrandLoader from '../../components/common/BrandLoader';
import {
  HiBuildingOffice2,
  HiMagnifyingGlass,
  HiXMark,
  HiEye,
  HiCheck,
  HiXCircle,
  HiTrash,
  HiChevronUpDown,
  HiMapPin,
  HiClock,
  HiCheckCircle,
  HiUser,
  HiShieldCheck,
  HiFunnel
} from 'react-icons/hi2';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';

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

const ConfirmModal = ({ isOpen, title, message, onConfirm, onCancel, loading, variant = 'danger' }) => {
  if (!isOpen) return null;
  const Icon = variant === 'danger' ? HiTrash : variant === 'success' ? HiCheck : HiShieldCheck;

  return (
    <AnimatePresence>
      <motion.div
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        exit={{ opacity: 0 }}
        className="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4"
      >
        <div className="absolute inset-0" onClick={onCancel} />
        <motion.div
          initial={{ scale: 0.96, opacity: 0, y: 8 }}
          animate={{ scale: 1, opacity: 1, y: 0 }}
          exit={{ scale: 0.96, opacity: 0, y: 8 }}
          transition={{ duration: 0.2, ease: [0.16, 1, 0.3, 1] }}
          className="relative w-full max-w-md bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xl z-10"
        >
          <button
            onClick={onCancel}
            className="absolute top-4 right-4 p-1.5 rounded-xl text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors"
          >
            <HiXMark className="w-5 h-5" />
          </button>

          <div
            className={`w-12 h-12 rounded-2xl flex items-center justify-center mx-auto mb-4 border shadow-2xs ${variant === 'danger'
                ? 'bg-rose-50 border-rose-100 text-rose-600'
                : variant === 'success'
                  ? 'bg-emerald-50 border-emerald-100 text-emerald-600'
                  : 'bg-slate-100 border-slate-200 text-slate-700'
              }`}
          >
            <Icon className="w-6 h-6" />
          </div>

          <h3 className="text-lg font-bold text-slate-900 text-center tracking-tight">{title}</h3>
          <p className="text-sm text-slate-500 text-center mt-2 leading-relaxed">{message}</p>

          <div className="flex gap-3 mt-6">
            <Button
              variant="outline"
              className="flex-1 rounded-xl border-slate-200 text-slate-700 hover:bg-slate-50 font-medium"
              onClick={onCancel}
              disabled={loading}
            >
              Cancel
            </Button>
            <Button
              variant={variant === 'danger' ? 'destructive' : 'default'}
              className={`flex-1 rounded-xl font-medium shadow-2xs ${variant === 'danger'
                  ? 'bg-rose-600 hover:bg-rose-700 text-white'
                  : variant === 'success'
                    ? 'bg-emerald-600 hover:bg-emerald-700 text-white'
                    : 'bg-slate-900 hover:bg-slate-800 text-white'
                }`}
              onClick={onConfirm}
              disabled={loading}
            >
              {loading ? (
                <BrandLoader size="small" showText={false} />
              ) : (
                'Confirm'
              )}
            </Button>
          </div>
        </motion.div>
      </motion.div>
    </AnimatePresence>
  );
};

const PropertiesSkeleton = () => (
  <div className="space-y-6">
    <div className="h-8 bg-slate-100 rounded-xl w-1/4 animate-pulse" />
    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
      {[...Array(4)].map((_, i) => (
        <div key={i} className="bg-white rounded-2xl border border-slate-200/80 p-5 space-y-3">
          <div className="w-10 h-10 bg-slate-100 rounded-xl animate-pulse" />
          <div className="h-7 bg-slate-100 rounded-lg w-1/2 animate-pulse" />
          <div className="h-4 bg-slate-100 rounded-lg w-2/3 animate-pulse" />
        </div>
      ))}
    </div>
    <div className="bg-white rounded-2xl border border-slate-200/80 overflow-hidden p-4 space-y-3">
      {[...Array(5)].map((_, i) => (
        <div key={i} className="h-12 bg-slate-50 rounded-xl animate-pulse" />
      ))}
    </div>
  </div>
);

const Properties = () => {
  const [properties, setProperties] = useState([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [modal, setModal] = useState({ open: false, id: null, title: '', action: null });
  const [processing, setProcessing] = useState(false);

  const fetchProperties = () => {
    setLoading(true);
    getProperties()
      .then((res) => setProperties(Array.isArray(res.data?.properties?.data) ? res.data.properties.data : []))
      .catch(() => toast.error('Failed to load properties'))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    fetchProperties();
  }, []);

  const filtered = properties.filter((p) => {
    const matchSearch =
      (p.title || '').toLowerCase().includes(search.toLowerCase()) ||
      (p.city || p.location || '').toLowerCase().includes(search.toLowerCase());
    const matchStatus = statusFilter === 'all' || p.status === statusFilter;
    return matchSearch && matchStatus;
  });

  const stats = {
    total: properties.length,
    approved: properties.filter((p) => p.status === 'approved').length,
    pending: properties.filter((p) => p.status === 'pending').length,
    rejected: properties.filter((p) => p.status === 'rejected').length,
  };

  const handleAction = async () => {
    if (!modal.id || !modal.action) return;
    setProcessing(true);
    try {
      if (modal.action === 'approve') {
        await approveProperty(modal.id);
        toast.success('Property approved');
        setProperties((prev) => prev.map((p) => (p.id === modal.id ? { ...p, status: 'approved' } : p)));
      } else if (modal.action === 'reject') {
        await rejectProperty(modal.id);
        toast.success('Property rejected');
        setProperties((prev) => prev.map((p) => (p.id === modal.id ? { ...p, status: 'rejected' } : p)));
      } else if (modal.action === 'delete') {
        await deleteProperty(modal.id);
        toast.success('Property deleted');
        setProperties((prev) => prev.filter((p) => p.id !== modal.id));
      }
      setModal({ open: false, id: null, title: '', action: null });
    } catch (err) {
      toast.error(err.response?.data?.message || 'Action failed');
    } finally {
      setProcessing(false);
    }
  };

  return (
    <DashboardLayout>
      <motion.div
        initial={{ opacity: 0, y: 10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.3, ease: 'easeOut' }}
        className="p-6 lg:p-8 max-w-7xl mx-auto space-y-6"
      >
        {/* Header Section */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-2 border-b border-slate-200/60">
          <div className="relative">
            <div className="flex items-center gap-3">
              {/* Orange Gradient Accent Bar */}
              <div className="h-7 w-1.5 rounded-full bg-gradient-to-b from-orange-500 to-amber-500 shadow-sm" />

              <div className="flex items-center gap-3 flex-wrap">
                <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900 font-display">
                  Properties Management
                </h1>

                {/* Count Badge with Live Pulse Dot */}
                <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-orange-50 border border-orange-200/80 text-orange-700 shadow-2xs">
                  <span className="relative flex h-2 w-2">
                    <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-orange-400 opacity-75"></span>
                    <span className="relative inline-flex rounded-full h-2 w-2 bg-orange-500"></span>
                  </span>
                  {stats.total} {stats.total === 1 ? 'Property' : 'Properties'}
                </span>
              </div>
            </div>

            <p className="text-sm text-slate-500 mt-1.5 ml-4.5 font-normal leading-relaxed">
              Review, approve, and oversee all platform listings
            </p>
          </div>
        </div>

        {/* Stats Section */}
        {!loading && properties.length > 0 && (
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
            {[
              {
                label: 'Total Properties',
                value: stats.total,
                icon: HiBuildingOffice2,
                iconStyle: 'bg-slate-900 text-white border-slate-800',
              },
              {
                label: 'Approved',
                value: stats.approved,
                icon: HiCheckCircle,
                iconStyle: 'bg-emerald-50 text-emerald-600 border-emerald-100',
              },
              {
                label: 'Pending Approval',
                value: stats.pending,
                icon: HiClock,
                iconStyle: 'bg-amber-50 text-amber-600 border-amber-100',
              },
              {
                label: 'Rejected',
                value: stats.rejected,
                icon: HiXCircle,
                iconStyle: 'bg-rose-50 text-rose-600 border-rose-100',
              },
            ].map((s, i) => (
              <motion.div
                key={s.label}
                initial={{ opacity: 0, y: 12 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: i * 0.05, duration: 0.25 }}
                className="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-xs hover:border-slate-300 hover:shadow-md transition-all duration-200"
              >
                <div className="flex items-center justify-between">
                  <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">{s.label}</span>
                  <div className={`w-9 h-9 rounded-xl border flex items-center justify-center shrink-0 ${s.iconStyle}`}>
                    <s.icon className="w-5 h-5" />
                  </div>
                </div>
                <p className="text-2xl font-extrabold tracking-tight text-slate-900 mt-3">{s.value}</p>
              </motion.div>
            ))}
          </div>
        )}

        {/* Filter Controls */}
        <div className="flex flex-col sm:flex-row gap-3">
          <div className="relative flex-1">
            <HiMagnifyingGlass className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
            <Input
              type="text"
              placeholder="Search by title or city..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="pl-10 h-11 bg-white border-slate-200/80 focus:border-slate-400 focus:ring-slate-400/20 rounded-xl text-sm"
            />
          </div>
          <div className="relative shrink-0">
            <HiFunnel className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none" />
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="appearance-none pl-10 pr-9 py-2.5 h-11 rounded-xl border border-slate-200/80 bg-white text-sm text-slate-700 shadow-2xs focus:border-slate-400 focus:ring-2 focus:ring-slate-400/20 focus:outline-none transition-all min-w-[160px] font-medium cursor-pointer"
            >
              <option value="all">All Status</option>
              <option value="approved">Approved</option>
              <option value="pending">Pending</option>
              <option value="rejected">Rejected</option>
            </select>
            <HiChevronUpDown className="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none" />
          </div>
        </div>

        {/* Content Table / Skeleton / Empty */}
        {loading ? (
          <PropertiesSkeleton />
        ) : filtered.length === 0 ? (
          <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-12 text-center">
            <div className="w-14 h-14 bg-slate-50 border border-slate-100 rounded-2xl flex items-center justify-center mx-auto mb-4 text-slate-400">
              <HiBuildingOffice2 className="w-7 h-7" />
            </div>
            <h3 className="text-base font-bold text-slate-900 tracking-tight">
              {search || statusFilter !== 'all' ? 'No matching properties found' : 'No properties listed yet'}
            </h3>
            <p className="text-slate-500 text-sm mt-1 max-w-sm mx-auto">
              {search || statusFilter !== 'all'
                ? 'Try refining your search terms or filter selections.'
                : 'Property submissions will appear here once owners start listing.'}
            </p>
          </div>
        ) : (
          <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full text-left border-collapse">
                <thead>
                  <tr className="bg-slate-50/70 border-b border-slate-100">
                    <th className="text-[11px] font-bold uppercase tracking-wider text-slate-500 px-6 py-4">Property</th>
                    <th className="text-[11px] font-bold uppercase tracking-wider text-slate-500 px-6 py-4">Owner</th>
                    <th className="text-[11px] font-bold uppercase tracking-wider text-slate-500 px-6 py-4">Location</th>
                    <th className="text-[11px] font-bold uppercase tracking-wider text-slate-500 px-6 py-4">Price</th>
                    <th className="text-[11px] font-bold uppercase tracking-wider text-slate-500 px-6 py-4">Status</th>
                    <th className="text-right text-[11px] font-bold uppercase tracking-wider text-slate-500 px-6 py-4">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {filtered.map((p) => (
                    <tr key={p.id} className="hover:bg-slate-50/60 transition-colors duration-150">
                      {/* Property Title & Image */}
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-3.5">
                          <div className="w-11 h-11 rounded-xl bg-slate-100 border border-slate-200/60 flex items-center justify-center overflow-hidden shrink-0 shadow-2xs">
                            {p.images?.[0] ? (
                              <img src={getImageUrl(p.images[0])} alt="" className="w-full h-full object-cover" />
                            ) : (
                              <HiBuildingOffice2 className="w-5 h-5 text-slate-400" />
                            )}
                          </div>
                          <span className="text-sm font-bold text-slate-900 truncate max-w-[220px]">{p.title}</span>
                        </div>
                      </td>

                      {/* Owner */}
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-1.5 text-xs font-medium text-slate-600">
                          <HiUser className="w-3.5 h-3.5 text-slate-400 shrink-0" />
                          <span className="truncate">{p.owner?.full_name || p.owner?.name || '—'}</span>
                        </div>
                      </td>

                      {/* Location */}
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-1.5 text-xs font-medium text-slate-600">
                          <HiMapPin className="w-3.5 h-3.5 text-slate-400 shrink-0" />
                          <span className="truncate max-w-[130px]">{ p.location || '—'}</span>
                        </div>
                      </td>

                      {/* Price */}
                      <td className="px-6 py-4">
                        <span className="text-sm font-extrabold text-slate-900">
                          {(p.price_per_month || 0).toLocaleString()}
                        </span>
                        <span className="text-xs font-bold text-slate-500 ml-1">MAD</span>
                      </td>

                      {/* Status Badge */}
                      <td className="px-6 py-4">
                        <span
                          className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold ring-1 ring-inset capitalize shadow-2xs ${statusColors[p.status] || 'bg-slate-100 text-slate-600 ring-slate-200'
                            }`}
                        >
                          <span className={`w-1.5 h-1.5 rounded-full ${statusDots[p.status] || 'bg-slate-400'}`} />
                          {p.status}
                        </span>
                      </td>

                      {/* Actions */}
                      <td className="px-6 py-4 text-right">
                        <div className="flex items-center justify-end gap-1">
                          <Link
                            to={`/admin/properties/${p.id}`}
                            className="p-2 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors"
                            title="View Property"
                          >
                            <HiEye className="w-4 h-4" />
                          </Link>

                          {p.status === 'pending' && (
                            <>
                              <button
                                onClick={() =>
                                  setModal({
                                    open: true,
                                    id: p.id,
                                    title: 'Approve Property',
                                    action: 'approve',
                                  })
                                }
                                className="p-2 text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 rounded-lg transition-colors"
                                title="Approve"
                              >
                                <HiCheck className="w-4 h-4" />
                              </button>
                              <button
                                onClick={() =>
                                  setModal({ open: true, id: p.id, title: 'Reject Property', action: 'reject' })
                                }
                                className="p-2 text-amber-600 hover:text-amber-700 hover:bg-amber-50 rounded-lg transition-colors"
                                title="Reject"
                              >
                                <HiXCircle className="w-4 h-4" />
                              </button>
                            </>
                          )}

                          <button
                            onClick={() =>
                              setModal({ open: true, id: p.id, title: 'Delete Property', action: 'delete' })
                            }
                            className="p-2 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors"
                            title="Delete"
                          >
                            <HiTrash className="w-4 h-4" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {/* Modal Confirmation */}
        <ConfirmModal
          isOpen={modal.open}
          title={modal.title}
          message={
            modal.action === 'approve'
              ? 'Are you sure you want to approve this property listing for public display?'
              : modal.action === 'reject'
                ? 'Are you sure you want to reject this property submission?'
                : 'Are you sure you want to permanently delete this property? This action cannot be undone.'
          }
          variant={modal.action === 'delete' ? 'danger' : modal.action === 'approve' ? 'success' : 'default'}
          onConfirm={handleAction}
          onCancel={() => setModal({ open: false, id: null, title: '', action: null })}
          loading={processing}
        />
      </motion.div>
    </DashboardLayout>
  );
};

export default Properties;