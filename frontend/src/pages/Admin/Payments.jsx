import { useState, useEffect } from 'react';
import DashboardLayout from '../../components/layouts/DashboardLayout';
import { getPayments, updatePaymentStatus, deletePayment } from '../../services/adminService';
import toast from 'react-hot-toast';
import {
  HiBanknotes,
  HiCalendarDays,
  HiCheckCircle,
  HiClock,
  HiCreditCard,
  HiEye,
  HiTrash,
  HiUser,
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
  InlineText,
  Money,
  PageHeader,
  SearchInput,
  StatGrid,
  StatusSelect,
  TableCard,
  Td,
  Th,
  ThRow,
  Toolbar,
  Tr,
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

const Payments = () => {
  const [payments, setPayments] = useState([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [deleteModal, setDeleteModal] = useState({ open: false, id: null, title: '' });
  const [pendingId, setPendingId] = useState(null);
  const [processing, setProcessing] = useState(false);

  const fetchPayments = () => {
    setLoading(true);
    getPayments()
      .then((res) =>
        setPayments(
          Array.isArray(res.data?.payments?.data)
            ? res.data.payments.data.map((p) => ({ ...p, property: nestedProperty(p) }))
            : []
        )
      )
      .catch(() => toast.error('Failed to load payments'))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    fetchPayments();
  }, []);

  const query = search.trim().toLowerCase();
  const filtered = payments.filter((p) => {
    const matchSearch =
      (p.property?.title || '').toLowerCase().includes(query) ||
      (p.tenant?.full_name || p.tenant?.name || '').toLowerCase().includes(query);
    const matchStatus = statusFilter === 'all' || p.status === statusFilter;
    return matchSearch && matchStatus;
  });

  const stats = {
    total: payments.length,
    revenue: payments.reduce((sum, p) => sum + Number(p.amount || 0), 0),
    paid: payments.filter((p) => p.status === 'paid' || p.status === 'completed').length,
    pending: payments.filter((p) => p.status === 'pending').length,
    failed: payments.filter((p) => p.status === 'failed').length,
  };

  const hasFilters = Boolean(query) || statusFilter !== 'all';
  const clearFilters = () => {
    setSearch('');
    setStatusFilter('all');
  };

  const handleStatusUpdate = async (payment, newStatus) => {
    setPendingId(payment.id);
    setProcessing(true);
    try {
      await updatePaymentStatus(payment.id, newStatus);
      toast.success(`Payment marked as ${newStatus}`);
      setPayments((prev) => prev.map((p) => (p.id === payment.id ? { ...p, status: newStatus } : p)));
    } catch (err) {
      toast.error(err.response?.data?.message || 'Failed to update status');
    } finally {
      setProcessing(false);
      setPendingId(null);
    }
  };

  const handleDelete = async () => {
    if (!deleteModal.id) return;
    setProcessing(true);
    try {
      await deletePayment(deleteModal.id);
      toast.success('Payment deleted');
      setPayments((prev) => prev.filter((p) => p.id !== deleteModal.id));
      setDeleteModal({ open: false, id: null, title: '' });
    } catch (err) {
      toast.error(err.response?.data?.message || 'Failed to delete');
    } finally {
      setProcessing(false);
    }
  };

  return (
    <DashboardLayout>
      <AdminPage>
        <PageHeader
          title="Payments Management"
          subtitle="Reconcile every transaction recorded across the platform"
          countText={`${stats.total} ${stats.total === 1 ? 'Payment' : 'Payments'}`}
        />

        {!loading && payments.length > 0 && (
          <StatGrid
            columns="grid-cols-2 sm:grid-cols-3 lg:grid-cols-5"
            items={[
              {
                label: 'Total',
                value: stats.total,
                icon: HiCreditCard,
                iconStyle: 'bg-slate-900 text-white border-slate-800',
                onClick: () => setStatusFilter('all'),
                title: 'Show all payments',
              },
              {
                label: 'Revenue',
                value: `${stats.revenue.toLocaleString()} MAD`,
                valueClassName: 'text-xl',
                icon: HiBanknotes,
                iconStyle: 'bg-orange-50 text-orange-600 border-orange-100',
              },
              {
                label: 'Paid',
                value: stats.paid,
                icon: HiCheckCircle,
                iconStyle: 'bg-emerald-50 text-emerald-600 border-emerald-100',
                onClick: () => setStatusFilter('paid'),
                title: 'Filter paid',
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
                label: 'Failed',
                value: stats.failed,
                icon: HiXCircle,
                iconStyle: 'bg-rose-50 text-rose-600 border-rose-100',
                onClick: () => setStatusFilter('failed'),
                title: 'Filter failed',
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
            options={[{ value: 'all', label: 'All Status' }, ...STATUS_OPTIONS]}
          />
        </Toolbar>

        {loading ? (
          <AdminSkeleton statCount={5} statColumns="grid-cols-2 sm:grid-cols-3 lg:grid-cols-5" rows={5} />
        ) : filtered.length === 0 ? (
          <EmptyState
            icon={HiCreditCard}
            title={hasFilters ? 'No payments match your filters' : 'No payments yet'}
            message={
              hasFilters
                ? 'Try a different search term or reset the status filter.'
                : 'Transactions appear here as soon as tenants start paying.'
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
                  <Th>Amount</Th>
                  <Th>Method</Th>
                  <Th>Status</Th>
                  <Th>Date</Th>
                  <Th align="right">Actions</Th>
                </ThRow>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {filtered.map((p) => (
                  <Tr key={p.id}>
                    <Td>
                      <span className="text-sm font-bold text-slate-900 block truncate max-w-[180px]">
                        {p.property?.title || '—'}
                      </span>
                    </Td>
                    <Td>
                      <InlineText icon={HiUser} className="text-sm">
                        {p.tenant?.full_name || p.tenant?.name || '—'}
                      </InlineText>
                    </Td>
                    <Td>
                      <Money value={p.amount} className="text-sm" />
                    </Td>
                    <Td>
                      <InlineText icon={HiCreditCard} className="text-sm capitalize">
                        {p.payment_method || p.method || '—'}
                      </InlineText>
                    </Td>
                    <Td>
                      <StatusSelect
                        value={p.status || 'pending'}
                        options={STATUS_OPTIONS}
                        map={PAYMENT_STATUS_MAP}
                        loading={pendingId === p.id}
                        disabled={processing && pendingId !== p.id}
                        onChange={(next) => handleStatusUpdate(p, next)}
                      />
                    </Td>
                    <Td>
                      <InlineText icon={HiCalendarDays}>
                        {formatDate(p.payment_date || p.created_at)}
                      </InlineText>
                    </Td>
                    <Td align="right">
                      <div className="flex items-center justify-end gap-1">
                        <ActionLink to={`/admin/payments/${p.id}`} tone="neutral" title="View Payment">
                          <HiEye className="w-4 h-4" />
                        </ActionLink>
                        <ActionButton
                          tone="danger"
                          title="Delete"
                          onClick={() =>
                            setDeleteModal({
                              open: true,
                              id: p.id,
                              title: p.property?.title || 'this payment',
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
          title="Delete Payment"
          message={`Are you sure you want to delete the payment for "${deleteModal.title}"? This action cannot be undone.`}
          confirmLabel="Delete"
          onConfirm={handleDelete}
          onCancel={() => setDeleteModal({ open: false, id: null, title: '' })}
          loading={processing}
        />
      </AdminPage>
    </DashboardLayout>
  );
};

export default Payments;