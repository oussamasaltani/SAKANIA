import { useState, useEffect } from 'react';
import DashboardLayout from '../../components/layouts/DashboardLayout';
import { getUsers, activateUser, deactivateUser, deleteUser } from '../../services/adminService';
import toast from 'react-hot-toast';
import {
  HiArrowPath,
  HiNoSymbol,
  HiBriefcase,
  HiCheck,
  HiCheckCircle,
  HiEnvelope,
  HiEye,
  HiShieldCheck,
  HiTrash,
  HiUserGroup,
  HiUsers,
} from 'react-icons/hi2';
import UserAvatar from '../../components/common/UserAvatar';
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
  PageHeader,
  RoleBadge,
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

const USER_STATUS_MAP = { active: 'emerald', inactive: 'rose' };

const roleOf = (user) => user?.role?.name || user?.role;

const Users = () => {
  const [users, setUsers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [roleFilter, setRoleFilter] = useState('all');
  const [deleteModal, setDeleteModal] = useState({ open: false, id: null, name: '' });
  const [processing, setProcessing] = useState(false);
  const [pendingId, setPendingId] = useState(null);

  const fetchUsers = () => {
    setLoading(true);
    getUsers()
      .then((res) =>
        setUsers(
          Array.isArray(res.data?.users?.data)
            ? res.data.users.data.map((u) => ({ ...u, status: u.is_active ? 'active' : 'inactive' }))
            : []
        )
      )
      .catch(() => toast.error('Failed to load users'))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    fetchUsers();
  }, []);

  const query = search.trim().toLowerCase();
  const filtered = users.filter((u) => {
    const matchSearch =
      (u.full_name || u.name || '').toLowerCase().includes(query) ||
      (u.email || '').toLowerCase().includes(query);
    const matchRole = roleFilter === 'all' || roleOf(u) === roleFilter;
    return matchSearch && matchRole;
  });

  const stats = {
    total: users.length,
    owners: users.filter((u) => roleOf(u) === 'owner').length,
    tenants: users.filter((u) => roleOf(u) === 'tenant').length,
    admins: users.filter((u) => roleOf(u) === 'admin').length,
    active: users.filter((u) => u.status === 'active').length,
    inactive: users.filter((u) => u.status === 'inactive').length,
  };

  const hasFilters = Boolean(query) || roleFilter !== 'all';
  const clearFilters = () => {
    setSearch('');
    setRoleFilter('all');
  };

  const handleToggleStatus = async (user) => {
    setPendingId(user.id);
    try {
      if (user.status === 'active') {
        await deactivateUser(user.id);
        toast.success(`${user.full_name || user.name} deactivated`);
      } else {
        await activateUser(user.id);
        toast.success(`${user.full_name || user.name} activated`);
      }
      setUsers((prev) =>
        prev.map((u) => (u.id === user.id ? { ...u, status: u.status === 'active' ? 'inactive' : 'active' } : u))
      );
    } catch (err) {
      toast.error(err.response?.data?.message || 'Action failed');
    } finally {
      setPendingId(null);
    }
  };

  const handleDelete = async () => {
    if (!deleteModal.id) return;
    setProcessing(true);
    try {
      await deleteUser(deleteModal.id);
      toast.success('User deleted successfully');
      setUsers((prev) => prev.filter((u) => u.id !== deleteModal.id));
      setDeleteModal({ open: false, id: null, name: '' });
    } catch (err) {
      toast.error(err.response?.data?.message || 'Failed to delete user');
    } finally {
      setProcessing(false);
    }
  };

  return (
    <DashboardLayout>
      <AdminPage>
        <PageHeader
          title="Users Management"
          subtitle="Manage accounts, roles, and access across the platform"
          countText={`${stats.total} ${stats.total === 1 ? 'User' : 'Users'}`}
        />

        {!loading && users.length > 0 && (
          <StatGrid
            columns="grid-cols-2 sm:grid-cols-3 lg:grid-cols-6"
            items={[
              {
                label: 'Total Users',
                value: stats.total,
                icon: HiUsers,
                iconStyle: 'bg-slate-900 text-white border-slate-800',
                onClick: () => setRoleFilter('all'),
                title: 'Show all users',
              },
              {
                label: 'Owners',
                value: stats.owners,
                icon: HiBriefcase,
                iconStyle: 'bg-sky-50 text-sky-600 border-sky-100',
                onClick: () => setRoleFilter('owner'),
                title: 'Filter by owners',
              },
              {
                label: 'Tenants',
                value: stats.tenants,
                icon: HiUserGroup,
                iconStyle: 'bg-emerald-50 text-emerald-600 border-emerald-100',
                onClick: () => setRoleFilter('tenant'),
                title: 'Filter by tenants',
              },
              {
                label: 'Admins',
                value: stats.admins,
                icon: HiShieldCheck,
                iconStyle: 'bg-violet-50 text-violet-600 border-violet-100',
                onClick: () => setRoleFilter('admin'),
                title: 'Filter by admins',
              },
              {
                label: 'Active',
                value: stats.active,
                icon: HiCheckCircle,
                iconStyle: 'bg-emerald-50 text-emerald-600 border-emerald-100',
              },
              {
                label: 'Inactive',
                value: stats.inactive,
                icon: HiNoSymbol,
                iconStyle: 'bg-rose-50 text-rose-600 border-rose-100',
              },
            ]}
          />
        )}

        <Toolbar>
          <SearchInput value={search} onChange={setSearch} placeholder="Search by name or email..." />
          <FilterSelect
            value={roleFilter}
            onChange={setRoleFilter}
            ariaLabel="Filter by role"
            options={[
              { value: 'all', label: 'All Roles' },
              { value: 'admin', label: 'Admin' },
              { value: 'owner', label: 'Owner' },
              { value: 'tenant', label: 'Tenant' },
            ]}
          />
        </Toolbar>

        {loading ? (
          <AdminSkeleton statCount={6} statColumns="grid-cols-2 sm:grid-cols-3 lg:grid-cols-6" rows={6} />
        ) : filtered.length === 0 ? (
          <EmptyState
            icon={HiUsers}
            title={hasFilters ? 'No users match your filters' : 'No users yet'}
            message={
              hasFilters
                ? 'Try a different search term or reset the role filter.'
                : 'Registered accounts will appear here once they sign up.'
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
                  <Th>User</Th>
                  <Th>Role</Th>
                  <Th>Status</Th>
                  <Th>Joined</Th>
                  <Th align="right">Actions</Th>
                </ThRow>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {filtered.map((u) => {
                  const busy = pendingId === u.id;
                  return (
                    <Tr key={u.id}>
                      <Td>
                        <div className="flex items-center gap-3.5 min-w-0">
                          <div className="w-10 h-10 rounded-full bg-slate-100 border border-slate-200/60 flex items-center justify-center shrink-0 overflow-hidden shadow-2xs">
                            <UserAvatar user={u} className="w-full h-full text-slate-500 text-xs font-bold" />
                          </div>
                          <div className="min-w-0">
                            <p className="text-sm font-bold text-slate-900 truncate">{u.full_name || u.name}</p>
                            <InlineText icon={HiEnvelope} className="mt-0.5">
                              {u.email || '—'}
                            </InlineText>
                          </div>
                        </div>
                      </Td>
                      <Td>
                        <RoleBadge role={roleOf(u)} />
                      </Td>
                      <Td>
                        <StatusBadge status={u.status} map={USER_STATUS_MAP} />
                      </Td>
                      <Td>
                        <InlineText>{formatDate(u.created_at)}</InlineText>
                      </Td>
                      <Td align="right">
                        <div className="flex items-center justify-end gap-1">
                          <ActionLink to={`/admin/users/${u.id}`} tone="neutral" title="View User">
                            <HiEye className="w-4 h-4" />
                          </ActionLink>
                          <ActionButton
                            tone={u.status === 'active' ? 'warning' : 'success'}
                            title={u.status === 'active' ? 'Deactivate' : 'Activate'}
                            disabled={busy}
                            onClick={() => handleToggleStatus(u)}
                          >
                            {busy ? (
                              <HiArrowPath className="w-4 h-4 animate-spin" />
                            ) : u.status === 'active' ? (
                              <HiNoSymbol className="w-4 h-4" />
                            ) : (
                              <HiCheck className="w-4 h-4" />
                            )}
                          </ActionButton>
                          <ActionButton
                            tone="danger"
                            title="Delete"
                            onClick={() => setDeleteModal({ open: true, id: u.id, name: u.full_name || u.name })}
                          >
                            <HiTrash className="w-4 h-4" />
                          </ActionButton>
                        </div>
                      </Td>
                    </Tr>
                  );
                })}
              </tbody>
            </DataTable>
          </TableCard>
        )}

        <ConfirmModal
          isOpen={deleteModal.open}
          title="Delete User"
          message={`Are you sure you want to delete "${deleteModal.name}"? This action cannot be undone.`}
          confirmLabel="Delete"
          onConfirm={handleDelete}
          onCancel={() => setDeleteModal({ open: false, id: null, name: '' })}
          loading={processing}
        />
      </AdminPage>
    </DashboardLayout>
  );
};

export default Users;