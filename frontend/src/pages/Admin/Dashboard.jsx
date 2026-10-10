import { useState, useEffect } from 'react';
import { useAuth } from '../../contexts/AuthContext';
import DashboardLayout from '../../components/layouts/DashboardLayout';
import { getDashboard, getPayments } from '../../services/adminService';
import { getImageUrl } from '../../utils/getImageUrl';
import UserAvatar from '../../components/common/UserAvatar';
import { motion } from 'framer-motion';
import {
  HiArrowPath,
  HiBanknotes,
  HiBuildingOffice2,
  HiCalendarDays,
  HiClock,
  HiCreditCard,
  HiDocumentText,
  HiHome,
  HiUsers,
  HiUser,
} from 'react-icons/hi2';
import {
  AdminPage,
  DataTable,
  ImageTile,
  InlineText,
  Money,
  PageHeader,
  SectionCard,
  StatGrid,
  StatusBadge,
  Td,
  Th,
  ThRow,
  Tr,
  ViewAllLink,
  formatDateTime,
  nestedProperty,
} from '../../components/admin/AdminUI';

const DASHBOARD_STATUS_MAP = {
  pending: 'amber',
  accepted: 'emerald',
  approved: 'emerald',
  completed: 'emerald',
  paid: 'emerald',
  rejected: 'rose',
  failed: 'rose',
  cancelled: 'rose',
  refunded: 'rose',
  inactive: 'rose',
};

const roleLabel = (role) => {
  const name = role?.name || role;
  return String(name || 'member').replace(/_/g, ' ');
};

const formatMoney = (value) => `${Number(value || 0).toLocaleString()} MAD`;

const InlineEmpty = ({ icon: Icon, title, hint }) => (
  <div className="py-10 text-center text-slate-400">
    <div className="w-11 h-11 rounded-2xl bg-slate-50 border border-slate-100 flex items-center justify-center mx-auto mb-3">
      <Icon className="w-5 h-5" />
    </div>
    <p className="text-sm font-bold text-slate-600">{title}</p>
    {hint && <p className="text-xs text-slate-400 mt-1">{hint}</p>}
  </div>
);

const fadeUp = {
  initial: { opacity: 0, y: 12 },
  animate: { opacity: 1, y: 0 },
};

const DashboardSkeleton = () => (
  <div className="space-y-6">
    <div className="flex items-center gap-3">
      <div className="h-7 w-1.5 rounded-full bg-slate-100 animate-pulse" />
      <div className="h-8 bg-slate-100 rounded-xl w-1/3 animate-pulse" />
    </div>
    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
      {Array.from({ length: 6 }).map((_, i) => (
        <div key={i} className="bg-white rounded-2xl border border-slate-200/80 p-5 space-y-3">
          <div className="w-9 h-9 bg-slate-100 rounded-xl animate-pulse" />
          <div className="h-7 bg-slate-100 rounded-lg w-1/2 animate-pulse" />
        </div>
      ))}
    </div>
    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
      {Array.from({ length: 3 }).map((_, i) => (
        <div key={i} className="bg-white rounded-2xl border border-slate-200/80 p-5 space-y-4">
          <div className="h-4 bg-slate-100 rounded w-1/3 animate-pulse" />
          <div className="h-32 bg-slate-100 rounded-xl animate-pulse" />
        </div>
      ))}
    </div>
  </div>
);

const MiniBarChart = ({ data, label, icon: Icon, barClass = 'bg-gradient-to-t from-orange-500 to-amber-400' }) => {
  const maxValue = Math.max(...data.map((d) => d.value), 1);

  return (
    <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-5">
      <div className="flex items-center justify-between gap-2">
        <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider truncate">
          {label}
        </span>
        <div className="w-9 h-9 rounded-xl border border-slate-200/80 bg-slate-50 text-slate-400 flex items-center justify-center shrink-0">
          <Icon className="w-5 h-5" />
        </div>
      </div>

      <div className="flex items-end gap-1.5 h-36 mt-4">
        {data.map((d, i) => (
          <div key={i} className="flex-1 flex flex-col items-center gap-1.5 min-w-0">
            <span className="text-[10px] font-bold text-slate-500 tabular-nums">
              {d.value > 0 ? d.value : ''}
            </span>
            <div
              title={`${d.label}: ${d.value}`}
              className={`w-full rounded-t-lg min-h-[4px] transition-all duration-500 ${barClass}`}
              style={{ height: `${Math.max((d.value / maxValue) * 100, d.value > 0 ? 6 : 2)}%` }}
            />
            <span className="text-[10px] font-medium text-slate-400 truncate w-full text-center">
              {d.label}
            </span>
          </div>
        ))}
      </div>
    </div>
  );
};

const Dashboard = () => {
  const { user } = useAuth();
  const [data, setData] = useState(null);
  const [payments, setPayments] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    Promise.allSettled([getDashboard(), getPayments({ per_page: 5 })])
      .then(([dashboardResult, paymentsResult]) => {
        if (dashboardResult.status === 'fulfilled') setData(dashboardResult.value.data);
        if (paymentsResult.status === 'fulfilled') {
          const rows = paymentsResult.value.data?.payments?.data;
          if (Array.isArray(rows)) {
            setPayments(rows.map((p) => ({ ...p, property: nestedProperty(p) })));
          }
        }
      })
      .finally(() => setLoading(false));
  }, []);

  if (loading) {
    return (
      <DashboardLayout>
        <AdminPage>
          <DashboardSkeleton />
        </AdminPage>
      </DashboardLayout>
    );
  }

  const stats = data?.stats || {};
  const charts = data?.charts || {};
  const reservationsPerMonth = charts?.reservations_per_month || {};
  const revenuePerMonth = charts?.revenue_per_month || {};
  const usersPerMonth = charts?.users_per_month || {};
  const recentUsers = data?.recent_users || [];
  const recentReservations = data?.recent_reservations || [];
  const recentPayments = payments;
  const activity = [
    ...recentUsers.map((u) => ({
      id: `user-${u.id}`,
      message: `${u.full_name || u.name || 'A user'} joined as ${roleLabel(u.role)}`,
      created_at: u.created_at,
      icon: HiUser,
    })),
    ...recentReservations.map((r) => ({
      id: `reservation-${r.id}`,
      message: `Reservation request for ${r.property?.title || 'a property'}`,
      created_at: r.created_at,
      icon: HiCalendarDays,
    })),
    ...(data?.recent_properties || []).map((p) => ({
      id: `property-${p.id}`,
      message: `${p.title || 'A property'} was submitted for review`,
      created_at: p.created_at,
      icon: HiBuildingOffice2,
    })),
    ...recentPayments.map((p) => ({
      id: `payment-${p.id}`,
      message: `Payment of ${formatMoney(p.amount)} recorded as ${p.status}`,
      created_at: p.payment_date || p.created_at,
      icon: HiBanknotes,
    })),
  ]
    .sort((a, b) => new Date(b.created_at || 0) - new Date(a.created_at || 0))
    .slice(0, 6);

  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const series = (source) =>
    months.map((m, i) => ({ label: m, value: source[i + 1] || source[m] || 0 }));

  const totalUsers = stats.total_users ?? 0;

  return (
    <DashboardLayout>
      <AdminPage>
        <PageHeader
          title="Admin Dashboard"
          subtitle={`Welcome back, ${user?.full_name || 'Admin'} — here is your platform overview.`}
          countText={`${totalUsers} ${totalUsers === 1 ? 'User' : 'Users'}`}
        />

        <StatGrid
          columns="grid-cols-1 sm:grid-cols-2 lg:grid-cols-3"
          items={[
            {
              label: 'Total Users',
              value: stats.total_users ?? 0,
              icon: HiUsers,
              iconStyle: 'bg-slate-900 text-white border-slate-800',
              to: '/admin/users',
            },
            {
              label: 'Total Properties',
              value: stats.total_properties ?? 0,
              icon: HiBuildingOffice2,
              iconStyle: 'bg-orange-50 text-orange-600 border-orange-100',
              to: '/admin/properties',
            },
            {
              label: 'Total Reservations',
              value: stats.total_reservations ?? 0,
              icon: HiCalendarDays,
              iconStyle: 'bg-sky-50 text-sky-600 border-sky-100',
              to: '/admin/reservations',
            },
            {
              label: 'Total Contracts',
              value: stats.total_contracts ?? 0,
              icon: HiDocumentText,
              iconStyle: 'bg-violet-50 text-violet-600 border-violet-100',
              to: '/admin/contracts',
            },
            {
              label: 'Total Payments',
              value: stats.total_payments ?? 0,
              icon: HiCreditCard,
              iconStyle: 'bg-emerald-50 text-emerald-600 border-emerald-100',
              to: '/admin/payments',
            },
            {
              label: 'Total Revenue',
              value: `${(stats.total_revenue ?? 0).toLocaleString()} MAD`,
              valueClassName: 'text-xl',
              icon: HiBanknotes,
              iconStyle: 'bg-gradient-to-b from-orange-500 to-amber-500 text-white border-orange-500',
              to: '/admin/payments',
            },
          ]}
        />

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <motion.div {...fadeUp} transition={{ delay: 0.08, duration: 0.35 }}>
            <MiniBarChart
              data={series(reservationsPerMonth)}
              label="Reservations per Month"
              icon={HiCalendarDays}
            />
          </motion.div>
          <motion.div {...fadeUp} transition={{ delay: 0.14, duration: 0.35 }}>
            <MiniBarChart
              data={series(revenuePerMonth)}
              label="Revenue per Month"
              icon={HiBanknotes}
              barClass="bg-gradient-to-t from-emerald-500 to-teal-400"
            />
          </motion.div>
          <motion.div {...fadeUp} transition={{ delay: 0.2, duration: 0.35 }}>
            <MiniBarChart
              data={series(usersPerMonth)}
              label="Users per Month"
              icon={HiUsers}
              barClass="bg-gradient-to-t from-violet-500 to-indigo-400"
            />
          </motion.div>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div className="lg:col-span-2">
            <SectionCard
              title="Recent Reservations"
              icon={HiCalendarDays}
              action={<ViewAllLink to="/admin/reservations" />}
            >
              {recentReservations.length > 0 ? (
                <div className="space-y-2">
                  {recentReservations.slice(0, 5).map((r, i) => (
                    <motion.div
                      key={r.id}
                      {...fadeUp}
                      transition={{ delay: i * 0.05, duration: 0.3 }}
                      className="flex items-center gap-3.5 p-2.5 -mx-2.5 rounded-xl hover:bg-slate-50 transition-colors duration-150"
                    >
                      <ImageTile
                        src={r.property?.images?.[0] ? getImageUrl(r.property.images[0]) : null}
                        icon={HiHome}
                        alt={r.property?.title || 'Property'}
                      />
                      <div className="flex-1 min-w-0">
                        <p className="text-sm font-bold text-slate-900 truncate">
                          {r.property?.title || 'Property'}
                        </p>
                        <InlineText icon={HiUser} className="mt-0.5">
                          {r.tenant?.full_name || r.tenant?.name || 'Tenant'}
                        </InlineText>
                      </div>
                      <StatusBadge status={r.status} map={DASHBOARD_STATUS_MAP} />
                    </motion.div>
                  ))}
                </div>
              ) : (
                <InlineEmpty
                  icon={HiCalendarDays}
                  title="No reservations yet"
                  hint="Reservations will show up here as tenants book."
                />
              )}
            </SectionCard>
          </div>

          <SectionCard title="Recent Users" icon={HiUsers} action={<ViewAllLink to="/admin/users" />}>
            {recentUsers.length > 0 ? (
              <div className="space-y-2">
                {recentUsers.slice(0, 5).map((u, i) => (
                  <motion.div
                    key={u.id}
                    {...fadeUp}
                    transition={{ delay: i * 0.05, duration: 0.3 }}
                    className="flex items-center gap-3 p-2 -mx-2 rounded-xl hover:bg-slate-50 transition-colors duration-150"
                  >
                    <div className="w-10 h-10 rounded-full bg-slate-100 border border-slate-200/60 flex items-center justify-center shrink-0 overflow-hidden">
                      <UserAvatar user={u} className="w-full h-full text-slate-500 text-xs font-bold" />
                    </div>
                    <div className="min-w-0 flex-1">
                      <p className="text-sm font-bold text-slate-900 truncate">
                        {u.full_name || u.name}
                      </p>
                      <p className="text-xs text-slate-500 truncate">{u.email}</p>
                    </div>
                  </motion.div>
                ))}
              </div>
            ) : (
              <InlineEmpty icon={HiUsers} title="No users yet" hint="New accounts will appear here." />
            )}
          </SectionCard>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div className="lg:col-span-2">
            <SectionCard
              title="Recent Payments"
              icon={HiCreditCard}
              action={<ViewAllLink to="/admin/payments" />}
              body={false}
            >
              {recentPayments.length > 0 ? (
                <div className="overflow-x-auto">
                  <DataTable>
                    <thead>
                      <ThRow>
                        <Th>Property</Th>
                        <Th>Tenant</Th>
                        <Th>Amount</Th>
                        <Th>Status</Th>
                      </ThRow>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {recentPayments.slice(0, 5).map((p) => (
                        <Tr key={p.id}>
                          <Td>
                            <span className="text-sm font-bold text-slate-900 block truncate max-w-[180px]">
                              {p.property?.title || '—'}
                            </span>
                          </Td>
                          <Td>
                            <InlineText className="text-sm">
                              {p.tenant?.full_name || p.tenant?.name || '—'}
                            </InlineText>
                          </Td>
                          <Td>
                            <Money value={p.amount} className="text-sm" />
                          </Td>
                          <Td>
                            <StatusBadge status={p.status} map={DASHBOARD_STATUS_MAP} />
                          </Td>
                        </Tr>
                      ))}
                    </tbody>
                  </DataTable>
                </div>
              ) : (
                <InlineEmpty icon={HiCreditCard} title="No payments yet" hint="Transactions appear here." />
              )}
            </SectionCard>
          </div>

          <SectionCard title="Activity Feed" icon={HiArrowPath}>
            {activity.length > 0 ? (
              <div className="space-y-4">
                {activity.map((a, i) => {
                  const ActivityIcon = a.icon || HiClock;
                  return (
                  <motion.div
                    key={a.id}
                    initial={{ opacity: 0, x: 8 }}
                    animate={{ opacity: 1, x: 0 }}
                    transition={{ delay: i * 0.05, duration: 0.3 }}
                    className="flex items-start gap-3"
                  >
                    <div className="w-8 h-8 rounded-xl bg-slate-50 border border-slate-100 flex items-center justify-center shrink-0 text-slate-400">
                      <ActivityIcon className="w-4 h-4" />
                    </div>
                    <div className="min-w-0">
                      <p className="text-sm text-slate-700 leading-snug">{a.message}</p>
                      <p className="text-xs text-slate-400 mt-0.5">
                        {formatDateTime(a.created_at)}
                      </p>
                    </div>
                  </motion.div>
                  );
                })}
              </div>
            ) : (
              <InlineEmpty icon={HiClock} title="No recent activity" hint="Platform events appear here." />
            )}
          </SectionCard>
        </div>
      </AdminPage>
    </DashboardLayout>
  );
};

export default Dashboard;