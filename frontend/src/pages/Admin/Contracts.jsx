import { useState, useEffect } from 'react';
import DashboardLayout from '../../components/layouts/DashboardLayout';
import { getContracts, updateContractStatus, deleteContract } from '../../services/adminService';
import { getImageUrl } from '../../utils/getImageUrl';
import toast from 'react-hot-toast';
import {
  HiNoSymbol,
  HiCalendarDays,
  HiCheck,
  HiCheckCircle,
  HiDocumentText,
  HiEye,
  HiHome,
  HiTrash,
  HiUser,
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

const CONTRACT_STATUS_MAP = {
  active: 'emerald',
  completed: 'emerald',
  ended: 'rose',
  pending: 'amber',
  draft: 'slate',
};

const STATUS_OPTIONS = [
  { value: 'active', label: 'Active' },
  { value: 'ended', label: 'Ended' },
  { value: 'completed', label: 'Completed' },
  { value: 'draft', label: 'Draft' },
];

const Contracts = () => {
  const [contracts, setContracts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [deleteModal, setDeleteModal] = useState({ open: false, id: null, title: '' });
  const [pendingId, setPendingId] = useState(null);
  const [processing, setProcessing] = useState(false);

  const fetchContracts = () => {
    setLoading(true);
    getContracts()
      .then((res) =>
        setContracts(
          Array.isArray(res.data?.contracts?.data)
            ? res.data.contracts.data.map((c) => ({ ...c, property: nestedProperty(c) }))
            : []
        )
      )
      .catch(() => toast.error('Failed to load contracts'))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    fetchContracts();
  }, []);

  const query = search.trim().toLowerCase();
  const filtered = contracts.filter((c) => {
    const matchSearch =
      (c.property?.title || '').toLowerCase().includes(query) ||
      (c.tenant?.full_name || c.tenant?.name || '').toLowerCase().includes(query) ||
      (c.owner?.full_name || c.owner?.name || '').toLowerCase().includes(query);
    const matchStatus = statusFilter === 'all' || c.status === statusFilter;
    return matchSearch && matchStatus;
  });

  const stats = {
    total: contracts.length,
    active: contracts.filter((c) => c.status === 'active').length,
    ended: contracts.filter((c) => c.status === 'ended').length,
    completed: contracts.filter((c) => c.status === 'completed').length,
    draft: contracts.filter((c) => c.status === 'draft').length,
  };

  const hasFilters = Boolean(query) || statusFilter !== 'all';
  const clearFilters = () => {
    setSearch('');
    setStatusFilter('all');
  };

  const handleStatusUpdate = async (contract, newStatus) => {
    setPendingId(contract.id);
    setProcessing(true);
    try {
      await updateContractStatus(contract.id, newStatus);
      toast.success(`Contract marked as ${newStatus}`);
      setContracts((prev) =>
        prev.map((c) => (c.id === contract.id ? { ...c, status: newStatus } : c))
      );
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
      await deleteContract(deleteModal.id);
      toast.success('Contract deleted');
      setContracts((prev) => prev.filter((c) => c.id !== deleteModal.id));
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
          title="Contracts Management"
          subtitle="Monitor rental agreements and their lifecycle status"
          countText={`${stats.total} ${stats.total === 1 ? 'Contract' : 'Contracts'}`}
        />

        {!loading && contracts.length > 0 && (
          <StatGrid
            columns="grid-cols-2 sm:grid-cols-3 lg:grid-cols-5"
            items={[
              {
                label: 'Total',
                value: stats.total,
                icon: HiDocumentText,
                iconStyle: 'bg-slate-900 text-white border-slate-800',
                onClick: () => setStatusFilter('all'),
                title: 'Show all contracts',
              },
              {
                label: 'Active',
                value: stats.active,
                icon: HiCheckCircle,
                iconStyle: 'bg-emerald-50 text-emerald-600 border-emerald-100',
                onClick: () => setStatusFilter('active'),
                title: 'Filter active',
              },
              {
                label: 'Ended',
                value: stats.ended,
                icon: HiNoSymbol,
                iconStyle: 'bg-rose-50 text-rose-600 border-rose-100',
                onClick: () => setStatusFilter('ended'),
                title: 'Filter ended',
              },
              {
                label: 'Completed',
                value: stats.completed,
                icon: HiCheck,
                iconStyle: 'bg-emerald-50 text-emerald-600 border-emerald-100',
                onClick: () => setStatusFilter('completed'),
                title: 'Filter completed',
              },
              {
                label: 'Draft',
                value: stats.draft,
                icon: HiDocumentText,
                iconStyle: 'bg-slate-100 text-slate-600 border-slate-200',
                onClick: () => setStatusFilter('draft'),
                title: 'Filter drafts',
              },
            ]}
          />
        )}

        <Toolbar>
          <SearchInput
            value={search}
            onChange={setSearch}
            placeholder="Search by property, owner, or tenant..."
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
            icon={HiDocumentText}
            title={hasFilters ? 'No contracts match your filters' : 'No contracts yet'}
            message={
              hasFilters
                ? 'Try a different search term or reset the status filter.'
                : 'Contracts are created automatically once a reservation is confirmed.'
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
                  <Th>Owner</Th>
                  <Th>Tenant</Th>
                  <Th>Status</Th>
                  <Th>Created</Th>
                  <Th align="right">Actions</Th>
                </ThRow>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {filtered.map((c) => (
                  <Tr key={c.id}>
                    <Td>
                      <div className="flex items-center gap-3.5 min-w-0">
                        <ImageTile
                          src={c.property?.images?.[0] ? getImageUrl(c.property.images[0]) : null}
                          icon={HiHome}
                          alt={c.property?.title || 'Property'}
                        />
                        <span className="text-sm font-bold text-slate-900 truncate max-w-[180px]">
                          {c.property?.title || 'Property'}
                        </span>
                      </div>
                    </Td>
                    <Td>
                      <InlineText icon={HiUser} className="text-sm">
                        {c.owner?.full_name || c.owner?.name || '—'}
                      </InlineText>
                    </Td>
                    <Td>
                      <InlineText icon={HiUser} className="text-sm">
                        {c.tenant?.full_name || c.tenant?.name || '—'}
                      </InlineText>
                    </Td>
                    <Td>
                      <StatusSelect
                        value={c.status || 'active'}
                        options={STATUS_OPTIONS}
                        map={CONTRACT_STATUS_MAP}
                        loading={pendingId === c.id}
                        disabled={processing && pendingId !== c.id}
                        onChange={(next) => handleStatusUpdate(c, next)}
                      />
                    </Td>
                    <Td>
                      <InlineText icon={HiCalendarDays}>{formatDate(c.created_at)}</InlineText>
                    </Td>
                    <Td align="right">
                      <div className="flex items-center justify-end gap-1">
                        <ActionLink to={`/admin/contracts/${c.id}`} tone="neutral" title="View Contract">
                          <HiEye className="w-4 h-4" />
                        </ActionLink>
                        <ActionButton
                          tone="danger"
                          title="Delete"
                          onClick={() =>
                            setDeleteModal({
                              open: true,
                              id: c.id,
                              title: c.property?.title || 'this contract',
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
          title="Delete Contract"
          message={`Are you sure you want to delete the contract for "${deleteModal.title}"? This action cannot be undone.`}
          confirmLabel="Delete"
          onConfirm={handleDelete}
          onCancel={() => setDeleteModal({ open: false, id: null, title: '' })}
          loading={processing}
        />
      </AdminPage>
    </DashboardLayout>
  );
};

export default Contracts;