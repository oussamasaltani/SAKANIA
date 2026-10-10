import { useState, useEffect } from 'react';
import DashboardLayout from '../../components/layouts/DashboardLayout';
import { getReservations, deleteReservation } from '../../services/adminService';
import { getImageUrl } from '../../utils/getImageUrl';
import toast from 'react-hot-toast';
import {
  HiNoSymbol,
  HiCalendarDays,
  HiCheckCircle,
  HiClock,
  HiEye,
  HiHome,
  HiUser,
  HiTrash,
  HiXCircle,
} from 'react-icons/hi2';
import {
  ActionButton,
  ActionLink,
  AdminPage,
  AdminSkeleton,
  ConfirmModal,
  DataTable,
  EmptyState,
  FilterSelect,
  ImageTile,
  InlineText,
  PageHeader,
  SearchInput,
  StatGrid,
  StatusBadge,
  TableCard,
  Td,
  Th,
  ThRow,
  Toolbar,
  Tr,
  formatDate,
} from '../../components/admin/AdminUI';

const RESERVATION_STATUS_MAP = {
  pending: 'amber',
  accepted: 'emerald',
  rejected: 'rose',
  cancelled: 'rose',
};

const STATUS_OPTIONS = [
  { value: 'all', label: 'All Status' },
  { value: 'pending', label: 'Pending' },
  { value: 'accepted', label: 'Accepted' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'cancelled', label: 'Cancelled' },
];

const Reservations = () => {
  const [reservations, setReservations] = useState([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [deleteModal, setDeleteModal] = useState({ open: false, id: null, title: '' });
  const [deleting, setDeleting] = useState(false);

  const fetchReservations = () => {
    setLoading(true);
    getReservations()
      .then((res) =>
        setReservations(Array.isArray(res.data?.reservations?.data) ? res.data.reservations.data : [])
      )
      .catch(() => toast.error('Failed to load reservations'))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    fetchReservations();
  }, []);

  const query = search.trim().toLowerCase();
  const filtered = reservations.filter((r) => {
    const matchSearch =
      (r.property?.title || '').toLowerCase().includes(query) ||
      (r.tenant?.full_name || r.tenant?.name || '').toLowerCase().includes(query);
    const matchStatus = statusFilter === 'all' || r.status === statusFilter;
    return matchSearch && matchStatus;
  });

  const stats = {
    total: reservations.length,
    pending: reservations.filter((r) => r.status === 'pending').length,
    accepted: reservations.filter((r) => r.status === 'accepted').length,
    rejected: reservations.filter((r) => r.status === 'rejected').length,
    cancelled: reservations.filter((r) => r.status === 'cancelled').length,
  };

  const hasFilters = Boolean(query) || statusFilter !== 'all';
  const clearFilters = () => {
    setSearch('');
    setStatusFilter('all');
  };

  const handleDelete = async () => {
    if (!deleteModal.id) return;
    setDeleting(true);
    try {
      await deleteReservation(deleteModal.id);
      toast.success('Reservation deleted');
      setReservations((prev) => prev.filter((r) => r.id !== deleteModal.id));
      setDeleteModal({ open: false, id: null, title: '' });
    } catch (err) {
      toast.error(err.response?.data?.message || 'Failed to delete');
    } finally {
      setDeleting(false);
    }
  };

  return (
    <DashboardLayout>
      <AdminPage>
        <PageHeader
          title="Reservations Management"
          subtitle="Track every rental request from submission to outcome"
          countText={`${stats.total} ${stats.total === 1 ? 'Reservation' : 'Reservations'}`}
        />

        {!loading && reservations.length > 0 && (
          <StatGrid
            columns="grid-cols-2 sm:grid-cols-3 lg:grid-cols-5"
            items={[
              {
                label: 'Total',
                value: stats.total,
                icon: HiCalendarDays,
                iconStyle: 'bg-slate-900 text-white border-slate-800',
                onClick: () => setStatusFilter('all'),
                title: 'Show all reservations',
              },
              {
                label: 'Pending',
                value: stats.pending,
                icon: HiClock,
                iconStyle: 'bg-amber-50 text-amber-600 border-amber-100',
                onClick: () => setStatusFilter('pending'),
                title: 'Filter pending',
              },
              {
                label: 'Accepted',
                value: stats.accepted,
                icon: HiCheckCircle,
                iconStyle: 'bg-emerald-50 text-emerald-600 border-emerald-100',
                onClick: () => setStatusFilter('accepted'),
                title: 'Filter accepted',
              },
              {
                label: 'Rejected',
                value: stats.rejected,
                icon: HiXCircle,
                iconStyle: 'bg-rose-50 text-rose-600 border-rose-100',
                onClick: () => setStatusFilter('rejected'),
                title: 'Filter rejected',
              },
              {
                label: 'Cancelled',
                value: stats.cancelled,
                icon: HiNoSymbol,
                iconStyle: 'bg-slate-100 text-slate-600 border-slate-200',
                onClick: () => setStatusFilter('cancelled'),
                title: 'Filter cancelled',
              },
            ]}
          />
        )}

        <Toolbar>
          <SearchInput
            value={search}
            onChange={setSearch}
            placeholder="Search by property or tenant..."
          />
          <FilterSelect
            value={statusFilter}
            onChange={setStatusFilter}
            ariaLabel="Filter by status"
            options={STATUS_OPTIONS}
          />
        </Toolbar>

        {loading ? (
          <AdminSkeleton statCount={5} statColumns="grid-cols-2 sm:grid-cols-3 lg:grid-cols-5" rows={5} />
        ) : filtered.length === 0 ? (
          <EmptyState
            icon={HiCalendarDays}
            title={hasFilters ? 'No reservations match your filters' : 'No reservations yet'}
            message={
              hasFilters
                ? 'Try a different search term or reset the status filter.'
                : 'Tenant reservation requests will appear here as soon as they are made.'
            }
            action={
              hasFilters ? (
                <button
                  type="button"
                  onClick={clearFilters}
                  className="text-xs font-bold uppercase tracking-wider text-orange-600 hover:text-orange-700 transition-colors"
                >
                  Clear filters
                </button>
              ) : null
            }
          />
        ) : (
          <TableCard>
            <DataTable>
              <thead>
                <ThRow>
                  <Th>Property</Th>
                  <Th>Tenant</Th>
                  <Th>Dates</Th>
                  <Th>Status</Th>
                  <Th align="right">Actions</Th>
                </ThRow>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {filtered.map((r) => (
                  <Tr key={r.id}>
                    <Td>
                      <div className="flex items-center gap-3.5 min-w-0">
                        <ImageTile
                          src={r.property?.images?.[0] ? getImageUrl(r.property.images[0]) : null}
                          icon={HiHome}
                          alt={r.property?.title || 'Property'}
                        />
                        <span className="text-sm font-bold text-slate-900 truncate max-w-[220px]">
                          {r.property?.title || 'Property'}
                        </span>
                      </div>
                    </Td>
                    <Td>
                      <InlineText icon={HiUser} className="text-sm">
                        {r.tenant?.full_name || r.tenant?.name || '—'}
                      </InlineText>
                    </Td>
                    <Td>
                      <InlineText icon={HiCalendarDays}>
                        {r.start_date ? formatDate(r.start_date, null) : null}
                        {r.end_date ? ` – ${formatDate(r.end_date, null)}` : ''}
                      </InlineText>
                    </Td>
                    <Td>
                      <StatusBadge status={r.status} map={RESERVATION_STATUS_MAP} />
                    </Td>
                    <Td align="right">
                      <div className="flex items-center justify-end gap-1">
                        <ActionLink to={`/admin/reservations/${r.id}`} tone="neutral" title="View Reservation">
                          <HiEye className="w-4 h-4" />
                        </ActionLink>
                        <ActionButton
                          tone="danger"
                          title="Delete"
                          onClick={() =>
                            setDeleteModal({
                              open: true,
                              id: r.id,
                              title: r.property?.title || 'this reservation',
                            })
                          }
                        >
                          <HiTrash className="w-4 h-4" />
                        </ActionButton>
                      </div>
                    </Td>
                  </Tr>
                ))}
              </tbody>
            </DataTable>
          </TableCard>
        )}

        <ConfirmModal
          isOpen={deleteModal.open}
          title="Delete Reservation"
          message={`Are you sure you want to delete the reservation for "${deleteModal.title}"? This action cannot be undone.`}
          confirmLabel="Delete"
          onConfirm={handleDelete}
          onCancel={() => setDeleteModal({ open: false, id: null, title: '' })}
          loading={deleting}
        />
      </AdminPage>
    </DashboardLayout>
  );
};

export default Reservations;