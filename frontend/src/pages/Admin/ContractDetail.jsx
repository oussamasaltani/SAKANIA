import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import DashboardLayout from '../../components/layouts/DashboardLayout';
import { getContract, updateContractStatus, deleteContract } from '../../services/adminService';
import { getImageUrl } from '../../utils/getImageUrl';
import toast from 'react-hot-toast';
import {
  HiArrowPath,
  HiBanknotes,
  HiCalendarDays,
  HiClock,
  HiDocumentText,
  HiHome,
  HiIdentification,
  HiMapPin,
  HiTrash,
  HiUser,
} from 'react-icons/hi2';
import { Button } from '../../components/ui/button';
import {
  AdminPage,
  ConfirmModal,
  DetailHeader,
  DetailSkeleton,
  FilterSelect,
  ImageTile,
  InfoRow,
  SectionCard,
  Spinner,
  StatusBadge,
  formatDate,
  nestedProperty,
} from '../../components/admin/AdminUI';

const CONTRACT_STATUS_MAP = {
  active: 'emerald',
  completed: 'emerald',
  ended: 'rose',
  pending: 'amber',
  draft: 'slate',
};

const PAYMENT_STATUS_MAP = {
  paid: 'emerald',
  completed: 'emerald',
  pending: 'amber',
  failed: 'rose',
  refunded: 'rose',
};

const STATUS_OPTIONS = [
  { value: 'active', label: 'Active' },
  { value: 'ended', label: 'Ended' },
  { value: 'completed', label: 'Completed' },
  { value: 'draft', label: 'Draft' },
];

const ContractDetail = () => {
  const { id } = useParams();
  const navigate = useNavigate();
  const [contract, setContract] = useState(null);
  const [loading, setLoading] = useState(true);
  const [processing, setProcessing] = useState(false);
  const [deleteModal, setDeleteModal] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [statusForm, setStatusForm] = useState('');

  useEffect(() => {
    getContract(id)
      .then((res) => {
        const data = res.data?.data || res.data;
        const normalized = data ? { ...data, property: nestedProperty(data) } : null;
        setContract(normalized);
        setStatusForm(normalized?.status || 'active');
      })
      .catch(() => {
        toast.error('Failed to load contract');
        navigate('/admin/contracts');
      })
      .finally(() => setLoading(false));
  }, [id, navigate]);

  const handleStatusUpdate = async () => {
    setProcessing(true);
    try {
      await updateContractStatus(id, statusForm);
      toast.success('Contract status updated');
      setContract((prev) => ({ ...prev, status: statusForm }));
    } catch (err) {
      toast.error(err.response?.data?.message || 'Failed to update status');
      setStatusForm(contract?.status || 'active');
    } finally {
      setProcessing(false);
    }
  };

  const handleDelete = async () => {
    setDeleting(true);
    try {
      await deleteContract(id);
      toast.success('Contract deleted');
      navigate('/admin/contracts');
    } catch (err) {
      toast.error(err.response?.data?.message || 'Failed to delete');
    } finally {
      setDeleting(false);
    }
  };

  if (loading) {
    return (
      <DashboardLayout>
        <div className="p-6 lg:p-8 max-w-5xl mx-auto">
          <DetailSkeleton />
        </div>
      </DashboardLayout>
    );
  }

  if (!contract) return null;

  const dirty = statusForm !== contract.status;
  const reservation = contract.reservation || null;
  const propertyPrice = contract.property?.price_per_month || null;
  const durationMonths = (() => {
    const start = reservation?.start_date ? new Date(reservation.start_date) : null;
    const end = reservation?.end_date ? new Date(reservation.end_date) : null;
    if (!start || Number.isNaN(start.getTime()) || !end || Number.isNaN(end.getTime())) return null;
    const months =
      (end.getFullYear() - start.getFullYear()) * 12 + (end.getMonth() - start.getMonth());
    return months > 0 ? months : null;
  })();

  return (
    <DashboardLayout>
      <AdminPage className="max-w-5xl">
        <DetailHeader
          title="Contract Details"
          subtitle={`Contract #${contract.id}`}
          onBack={() => navigate('/admin/contracts')}
        >
          <StatusBadge status={contract.status} map={CONTRACT_STATUS_MAP} />
        </DetailHeader>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div className="lg:col-span-2 space-y-6">
            <SectionCard title="Contract Information" icon={HiDocumentText}>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <InfoRow
                  icon={HiCalendarDays}
                  label="Start Date"
                  value={formatDate(reservation?.start_date, null)}
                />
                <InfoRow
                  icon={HiCalendarDays}
                  label="End Date"
                  value={reservation?.end_date ? formatDate(reservation.end_date, null) : 'Ongoing'}
                />
                {durationMonths ? (
                  <InfoRow icon={HiClock} label="Duration" value={`${durationMonths} months`} />
                ) : null}
                {propertyPrice ? (
                  <InfoRow
                    icon={HiBanknotes}
                    label="Monthly Rent"
                    value={`${Number(propertyPrice).toLocaleString()} MAD`}
                  />
                ) : null}
                <InfoRow
                  icon={HiDocumentText}
                  label="Signed At"
                  value={contract.signed_at ? formatDate(contract.signed_at, null) : 'Not signed'}
                />
                <InfoRow
                  icon={HiDocumentText}
                  label="Document"
                  value={contract.file_url ? 'Attached' : 'Not uploaded'}
                />
              </div>
            </SectionCard>

            {contract.reservation && (
              <SectionCard title="Reservation Info" icon={HiCalendarDays}>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <InfoRow
                    icon={HiCalendarDays}
                    label="Reservation Dates"
                    value={
                      contract.reservation.start_date
                        ? `${formatDate(contract.reservation.start_date, null)} – ${
                            contract.reservation.end_date
                              ? formatDate(contract.reservation.end_date, null)
                              : 'Ongoing'
                          }`
                        : null
                    }
                  />
                  <InfoRow icon={HiClock} label="Status" value={contract.reservation.status} />
                </div>
              </SectionCard>
            )}

            {contract.property && (
              <SectionCard title="Property" icon={HiHome}>
                <div className="flex gap-4">
                  <ImageTile
                    src={
                      contract.property.images?.[0]
                        ? getImageUrl(contract.property.images[0])
                        : null
                    }
                    icon={HiHome}
                    alt={contract.property.title}
                    size="w-20 h-20 rounded-xl"
                  />
                  <div className="min-w-0">
                    <h3 className="text-sm font-extrabold text-slate-900 truncate">
                      {contract.property.title}
                    </h3>
                    <p className="text-sm text-slate-500 mt-1 flex items-center gap-1.5">
                      <HiMapPin className="w-4 h-4 text-slate-400 shrink-0" />
                      <span className="truncate">
                        {contract.property.city || contract.property.location || '—'}
                      </span>
                    </p>
                    {contract.property.price_per_month ? (
                      <p className="text-sm font-extrabold text-slate-900 mt-2">
                        {Number(contract.property.price_per_month).toLocaleString()}
                        <span className="text-xs font-bold text-slate-500 ml-1">MAD/mo</span>
                      </p>
                    ) : null}
                  </div>
                </div>
              </SectionCard>
            )}

            {contract.latestPayment && (
              <SectionCard title="Latest Payment" icon={HiBanknotes}>
                <div className="flex items-center justify-between gap-3 p-4 bg-slate-50/60 border border-slate-100 rounded-xl">
                  <div className="min-w-0">
                    <p className="text-lg font-extrabold text-slate-900">
                      {Number(contract.latestPayment.amount || 0).toLocaleString()}
                      <span className="text-xs font-bold text-slate-500 ml-1">MAD</span>
                    </p>
                    <p className="text-xs text-slate-500 mt-0.5">
                      {formatDate(contract.latestPayment.payment_date, null)}
                    </p>
                  </div>
                  <StatusBadge status={contract.latestPayment.status} map={PAYMENT_STATUS_MAP} />
                </div>
              </SectionCard>
            )}
          </div>

          <div className="space-y-6">
            <SectionCard title="Update Status" icon={HiArrowPath}>
              <p className="text-sm text-slate-500 leading-relaxed">
                {dirty ? 'Save the new status to apply it to this contract.' : 'Status is up to date.'}
              </p>
              <div className="flex gap-2 mt-5">
                <FilterSelect
                  value={statusForm}
                  onChange={setStatusForm}
                  ariaLabel="Contract status"
                  options={STATUS_OPTIONS}
                  fullWidth
                />
                <Button
                  onClick={handleStatusUpdate}
                  disabled={processing || !dirty}
                  className="rounded-xl shrink-0"
                >
                  {processing ? <Spinner /> : null}
                  Update
                </Button>
              </div>
            </SectionCard>

            {contract.owner && (
              <SectionCard title="Owner" icon={HiUser}>
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 rounded-full bg-slate-100 border border-slate-200/60 flex items-center justify-center shrink-0 text-slate-500 text-sm font-bold">
                    {(contract.owner.full_name || contract.owner.name || 'O').charAt(0).toUpperCase()}
                  </div>
                  <div className="min-w-0">
                    <p className="text-sm font-bold text-slate-900 truncate">
                      {contract.owner.full_name || contract.owner.name}
                    </p>
                    <p className="text-xs text-slate-500 truncate">{contract.owner.email}</p>
                  </div>
                </div>
              </SectionCard>
            )}

            {contract.tenant && (
              <SectionCard title="Tenant" icon={HiUser}>
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 rounded-full bg-slate-100 border border-slate-200/60 flex items-center justify-center shrink-0 text-slate-500 text-sm font-bold">
                    {(contract.tenant.full_name || contract.tenant.name || 'T').charAt(0).toUpperCase()}
                  </div>
                  <div className="min-w-0">
                    <p className="text-sm font-bold text-slate-900 truncate">
                      {contract.tenant.full_name || contract.tenant.name}
                    </p>
                    <p className="text-xs text-slate-500 truncate">{contract.tenant.email}</p>
                  </div>
                </div>
              </SectionCard>
            )}

            <SectionCard title="Danger Zone" icon={HiIdentification}>
              <p className="text-sm text-slate-500 leading-relaxed">
                Deleting a contract breaks the agreement between owner and tenant.
              </p>
              <Button onClick={() => setDeleteModal(true)} variant="destructive" className="w-full mt-5 rounded-xl">
                <HiTrash className="w-4 h-4" /> Delete Contract
              </Button>
            </SectionCard>

            <Button
              onClick={() => navigate('/admin/contracts')}
              variant="outline"
              className="w-full rounded-xl border-slate-200 text-slate-700 hover:bg-slate-50"
            >
              Back to Contracts
            </Button>
          </div>
        </div>

        <ConfirmModal
          isOpen={deleteModal}
          title="Delete Contract"
          message="Are you sure you want to delete this contract? This action cannot be undone."
          confirmLabel="Delete"
          onConfirm={handleDelete}
          onCancel={() => setDeleteModal(false)}
          loading={deleting}
        />
      </AdminPage>
    </DashboardLayout>
  );
};

export default ContractDetail;