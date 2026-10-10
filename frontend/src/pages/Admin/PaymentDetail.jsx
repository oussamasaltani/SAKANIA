import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import DashboardLayout from '../../components/layouts/DashboardLayout';
import { getPayment, updatePaymentStatus, deletePayment } from '../../services/adminService';
import { getImageUrl } from '../../utils/getImageUrl';
import toast from 'react-hot-toast';
import {
  HiArrowPath,
  HiBanknotes,
  HiCalendarDays,
  HiCheckCircle,
  HiClock,
  HiCreditCard,
  HiHashtag,
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

const PAYMENT_STATUS_MAP = {
  paid: 'emerald',
  completed: 'emerald',
  pending: 'amber',
  failed: 'rose',
  refunded: 'rose',
};

const STATUS_OPTIONS = [
  { value: 'pending', label: 'Pending' },
  { value: 'paid', label: 'Paid' },
  { value: 'failed', label: 'Failed' },
  { value: 'refunded', label: 'Refunded' },
];

const PaymentDetail = () => {
  const { id } = useParams();
  const navigate = useNavigate();
  const [payment, setPayment] = useState(null);
  const [loading, setLoading] = useState(true);
  const [processing, setProcessing] = useState(false);
  const [deleteModal, setDeleteModal] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [statusForm, setStatusForm] = useState('');

  useEffect(() => {
    getPayment(id)
      .then((res) => {
        const data = res.data?.data || res.data;
        const normalized = data ? { ...data, property: nestedProperty(data) } : null;
        setPayment(normalized);
        setStatusForm(normalized?.status || 'pending');
      })
      .catch(() => {
        toast.error('Failed to load payment');
        navigate('/admin/payments');
      })
      .finally(() => setLoading(false));
  }, [id, navigate]);

  const handleStatusUpdate = async () => {
    setProcessing(true);
    try {
      await updatePaymentStatus(id, statusForm);
      toast.success('Payment status updated');
      setPayment((prev) => ({ ...prev, status: statusForm }));
    } catch (err) {
      toast.error(err.response?.data?.message || 'Failed to update status');
      setStatusForm(payment?.status || 'pending');
    } finally {
      setProcessing(false);
    }
  };

  const handleDelete = async () => {
    setDeleting(true);
    try {
      await deletePayment(id);
      toast.success('Payment deleted');
      navigate('/admin/payments');
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

  if (!payment) return null;

  const dirty = statusForm !== payment.status;
  const tenantName = payment.tenant?.full_name || payment.tenant?.name;

  return (
    <DashboardLayout>
      <AdminPage className="max-w-5xl">
        <DetailHeader
          title="Payment Details"
          subtitle={`Payment #${payment.id}`}
          onBack={() => navigate('/admin/payments')}
        >
          <StatusBadge status={payment.status} map={PAYMENT_STATUS_MAP} />
        </DetailHeader>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div className="lg:col-span-2 space-y-6">
            <SectionCard title="Payment Information" icon={HiBanknotes}>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <InfoRow
                  icon={HiBanknotes}
                  label="Amount"
                  value={payment.amount ? `${Number(payment.amount).toLocaleString()} MAD` : null}
                />
                <InfoRow
                  icon={HiCheckCircle}
                  label="Paid Amount"
                  value={payment.paid_amount ? `${Number(payment.paid_amount).toLocaleString()} MAD` : null}
                />
                <InfoRow
                  icon={HiClock}
                  label="Remaining"
                  value={
                    payment.remaining_balance != null
                      ? `${Number(payment.remaining_balance).toLocaleString()} MAD`
                      : null
                  }
                />
                <InfoRow
                  icon={HiCreditCard}
                  label="Method"
                  value={payment.payment_method || payment.method}
                />
                <InfoRow
                  icon={HiHashtag}
                  label="Transaction Ref"
                  value={payment.transaction_ref || payment.reference}
                />
                <InfoRow
                  icon={HiCalendarDays}
                  label="Date"
                  value={formatDate(payment.payment_date || payment.created_at, null)}
                />
              </div>
            </SectionCard>

            {payment.reservation && (
              <SectionCard title="Reservation Info" icon={HiCalendarDays}>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <InfoRow
                    icon={HiCalendarDays}
                    label="Dates"
                    value={
                      payment.reservation.start_date
                        ? `${formatDate(payment.reservation.start_date, null)} – ${
                            payment.reservation.end_date
                              ? formatDate(payment.reservation.end_date, null)
                              : 'Ongoing'
                          }`
                        : null
                    }
                  />
                  <InfoRow icon={HiClock} label="Status" value={payment.reservation.status} />
                </div>
              </SectionCard>
            )}

            {payment.property && (
              <SectionCard title="Property" icon={HiHome}>
                <div className="flex gap-4">
                  <ImageTile
                    src={
                      payment.property.images?.[0] ? getImageUrl(payment.property.images[0]) : null
                    }
                    icon={HiHome}
                    alt={payment.property.title}
                    size="w-20 h-20 rounded-xl"
                  />
                  <div className="min-w-0">
                    <h3 className="text-sm font-extrabold text-slate-900 truncate">
                      {payment.property.title}
                    </h3>
                    <p className="text-sm text-slate-500 mt-1 flex items-center gap-1.5">
                      <HiMapPin className="w-4 h-4 text-slate-400 shrink-0" />
                      <span className="truncate">
                        {payment.property.city || payment.property.location || '—'}
                      </span>
                    </p>
                    {payment.property.price_per_month ? (
                      <p className="text-sm font-extrabold text-slate-900 mt-2">
                        {Number(payment.property.price_per_month).toLocaleString()}
                        <span className="text-xs font-bold text-slate-500 ml-1">MAD/mo</span>
                      </p>
                    ) : null}
                  </div>
                </div>
              </SectionCard>
            )}
          </div>

          <div className="space-y-6">
            <SectionCard title="Update Status" icon={HiArrowPath}>
              <p className="text-sm text-slate-500 leading-relaxed">
                {dirty ? 'Save the new status to update this payment.' : 'Status is up to date.'}
              </p>
              <div className="flex gap-2 mt-5">
                <FilterSelect
                  value={statusForm}
                  onChange={setStatusForm}
                  ariaLabel="Payment status"
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

            {payment.tenant && (
              <SectionCard title="Tenant" icon={HiUser}>
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 rounded-full bg-slate-100 border border-slate-200/60 flex items-center justify-center shrink-0 text-slate-500 text-sm font-bold">
                    {(tenantName || 'T').charAt(0).toUpperCase()}
                  </div>
                  <div className="min-w-0">
                    <p className="text-sm font-bold text-slate-900 truncate">{tenantName || '—'}</p>
                    <p className="text-xs text-slate-500 truncate">{payment.tenant.email}</p>
                  </div>
                </div>
              </SectionCard>
            )}

            <SectionCard title="Danger Zone" icon={HiIdentification}>
              <p className="text-sm text-slate-500 leading-relaxed">
                Deleting a payment removes the transaction from all revenue reporting.
              </p>
              <Button onClick={() => setDeleteModal(true)} variant="destructive" className="w-full mt-5 rounded-xl">
                <HiTrash className="w-4 h-4" /> Delete Payment
              </Button>
            </SectionCard>

            <Button
              onClick={() => navigate('/admin/payments')}
              variant="outline"
              className="w-full rounded-xl border-slate-200 text-slate-700 hover:bg-slate-50"
            >
              Back to Payments
            </Button>
          </div>
        </div>

        <ConfirmModal
          isOpen={deleteModal}
          title="Delete Payment"
          message="Are you sure you want to delete this payment record? This action cannot be undone."
          confirmLabel="Delete"
          onConfirm={handleDelete}
          onCancel={() => setDeleteModal(false)}
          loading={deleting}
        />
      </AdminPage>
    </DashboardLayout>
  );
};

export default PaymentDetail;