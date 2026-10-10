import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import DashboardLayout from '../../components/layouts/DashboardLayout';
import { getReservation, deleteReservation } from '../../services/adminService';
import { getImageUrl } from '../../utils/getImageUrl';
import toast from 'react-hot-toast';
import {
  HiBanknotes,
  HiCalendarDays,
  HiClock,
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
  ImageTile,
  InfoRow,
  SectionCard,
  StatusBadge,
  formatDate,
} from '../../components/admin/AdminUI';

const RESERVATION_STATUS_MAP = {
  pending: 'amber',
  accepted: 'emerald',
  rejected: 'rose',
  cancelled: 'rose',
};

const ReservationDetail = () => {
  const { id } = useParams();
  const navigate = useNavigate();
  const [reservation, setReservation] = useState(null);
  const [loading, setLoading] = useState(true);
  const [deleting, setDeleting] = useState(false);
  const [deleteModal, setDeleteModal] = useState(false);

  useEffect(() => {
    getReservation(id)
      .then((res) => setReservation(res.data?.data || res.data))
      .catch(() => {
        toast.error('Failed to load reservation');
        navigate('/admin/reservations');
      })
      .finally(() => setLoading(false));
  }, [id, navigate]);

  const handleDelete = async () => {
    setDeleting(true);
    try {
      await deleteReservation(id);
      toast.success('Reservation deleted');
      navigate('/admin/reservations');
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

  if (!reservation) return null;

  const property = reservation.property;
  const tenantName = reservation.tenant?.full_name || reservation.tenant?.name;

  return (
    <DashboardLayout>
      <AdminPage className="max-w-5xl">
        <DetailHeader
          title="Reservation Details"
          subtitle={`Reservation #${reservation.id}`}
          onBack={() => navigate('/admin/reservations')}
        >
          <StatusBadge status={reservation.status} map={RESERVATION_STATUS_MAP} />
        </DetailHeader>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div className="lg:col-span-2 space-y-6">
            <SectionCard title="Reservation Information" icon={HiCalendarDays}>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <InfoRow
                  icon={HiCalendarDays}
                  label="Start Date"
                  value={formatDate(reservation.start_date, null)}
                />
                <InfoRow
                  icon={HiCalendarDays}
                  label="End Date"
                  value={reservation.end_date ? formatDate(reservation.end_date, null) : 'Ongoing'}
                />
                <InfoRow
                  icon={HiClock}
                  label="Requested On"
                  value={formatDate(reservation.created_at, null)}
                />
                <InfoRow
                  icon={HiBanknotes}
                  label="Monthly Rent"
                  value={
                    property?.price_per_month
                      ? `${Number(property.price_per_month).toLocaleString()} MAD`
                      : null
                  }
                />
              </div>
            </SectionCard>

            {property && (
              <SectionCard title="Property" icon={HiHome}>
                <div className="flex gap-4">
                  <ImageTile
                    src={property.images?.[0] ? getImageUrl(property.images[0]) : null}
                    icon={HiHome}
                    alt={property.title}
                    size="w-20 h-20 rounded-xl"
                  />
                  <div className="min-w-0">
                    <h3 className="text-sm font-extrabold text-slate-900 truncate">{property.title}</h3>
                    <p className="text-sm text-slate-500 mt-1 flex items-center gap-1.5">
                      <HiMapPin className="w-4 h-4 text-slate-400 shrink-0" />
                      <span className="truncate">{property.city || property.location || '—'}</span>
                    </p>
                    {property.price_per_month ? (
                      <p className="text-sm font-extrabold text-slate-900 mt-2">
                        {Number(property.price_per_month).toLocaleString()}
                        <span className="text-xs font-bold text-slate-500 ml-1">MAD/mo</span>
                      </p>
                    ) : null}
                  </div>
                </div>
              </SectionCard>
            )}
          </div>

          <div className="space-y-6">
            {reservation.tenant && (
              <SectionCard title="Tenant" icon={HiUser}>
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 rounded-full bg-slate-100 border border-slate-200/60 flex items-center justify-center shrink-0 text-slate-500 text-sm font-bold">
                    {(tenantName || 'T').charAt(0).toUpperCase()}
                  </div>
                  <div className="min-w-0">
                    <p className="text-sm font-bold text-slate-900 truncate">{tenantName || '—'}</p>
                    {reservation.tenant.email && (
                      <p className="text-xs text-slate-500 truncate">{reservation.tenant.email}</p>
                    )}
                  </div>
                </div>
              </SectionCard>
            )}

            <SectionCard title="Danger Zone" icon={HiIdentification}>
              <p className="text-sm text-slate-500 leading-relaxed">
                Removing this reservation also removes its links to contracts and payments.
              </p>
              <Button onClick={() => setDeleteModal(true)} variant="destructive" className="w-full mt-5 rounded-xl">
                <HiTrash className="w-4 h-4" /> Delete Reservation
              </Button>
            </SectionCard>

            <Button
              onClick={() => navigate('/admin/reservations')}
              variant="outline"
              className="w-full rounded-xl border-slate-200 text-slate-700 hover:bg-slate-50"
            >
              Back to Reservations
            </Button>
          </div>
        </div>

        <ConfirmModal
          isOpen={deleteModal}
          title="Delete Reservation"
          message="Are you sure you want to delete this reservation? This action cannot be undone."
          confirmLabel="Delete"
          onConfirm={handleDelete}
          onCancel={() => setDeleteModal(false)}
          loading={deleting}
        />
      </AdminPage>
    </DashboardLayout>
  );
};

export default ReservationDetail;