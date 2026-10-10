import { lazy, Suspense } from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import { Toaster } from 'react-hot-toast';
import { AuthProvider, useAuth } from './contexts/AuthContext';
import { FavoritesProvider } from './contexts/FavoritesContext';
import DashboardLayout from './components/layouts/DashboardLayout';
import PageLoader from './components/common/PageLoader';
import Chatbot from './components/common/Chatbot';

const Login = lazy(() => import('./pages/Auth/Login'));
const Register = lazy(() => import('./pages/Auth/Register'));
// Where the API sends the browser after Google has answered. Without this route
// the catch-all below would throw the code away and send the user home.
const GoogleCallback = lazy(() => import('./pages/Auth/GoogleCallback'));
const Home = lazy(() => import('./pages/Public/Home'));
const PropertyList = lazy(() => import('./pages/Public/PropertyList'));
const PropertyDetail = lazy(() => import('./pages/Public/PropertyDetail'));
const TenantDashboard = lazy(() => import('./pages/Tenant/Dashboard'));
const TenantReservations = lazy(() => import('./pages/Tenant/MyReservations'));
const TenantContracts = lazy(() => import('./pages/Tenant/MyContracts'));
const TenantContractDetail = lazy(() => import('./pages/Tenant/ContractDetails'));
const TenantPayments = lazy(() => import('./pages/Tenant/Payments'));
const TenantFavorites = lazy(() => import('./pages/Tenant/Favorites'));
const Messages = lazy(() => import('./pages/Messages'));
const OwnerDashboardPage = lazy(() => import('./pages/Owner/Dashboard'));
const OwnerPropertiesPage = lazy(() => import('./pages/Owner/Properties'));
const OwnerPropertyAddPage = lazy(() => import('./pages/Owner/AddProperty'));
const OwnerPropertyEditPage = lazy(() => import('./pages/Owner/EditProperty'));
const OwnerReservationsPage = lazy(() => import('./pages/Owner/Reservations'));
const OwnerReservationDetailPage = lazy(() => import('./pages/Owner/ReservationDetails'));
const OwnerContractsPage = lazy(() => import('./pages/Owner/Contracts'));
const OwnerContractDetailPage = lazy(() => import('./pages/Owner/ContractDetails'));
const OwnerPaymentsPage = lazy(() => import('./pages/Owner/Payments'));
const AdminDashboardPage = lazy(() => import('./pages/Admin/Dashboard'));
const AdminUsersPage = lazy(() => import('./pages/Admin/Users'));
const AdminUserDetailPage = lazy(() => import('./pages/Admin/UserDetail'));
const AdminPropertiesPage = lazy(() => import('./pages/Admin/Properties'));
const AdminPropertyDetailPage = lazy(() => import('./pages/Admin/PropertyDetail'));
const AdminReservationsPage = lazy(() => import('./pages/Admin/Reservations'));
const AdminReservationDetailPage = lazy(() => import('./pages/Admin/ReservationDetail'));
const AdminContractsPage = lazy(() => import('./pages/Admin/Contracts'));
const AdminContractDetailPage = lazy(() => import('./pages/Admin/ContractDetail'));
const AdminPaymentsPage = lazy(() => import('./pages/Admin/Payments'));
const AdminPaymentDetailPage = lazy(() => import('./pages/Admin/PaymentDetail'));

// One profile page for every role: it edits the signed-in account and nothing
// else, so there is no reason for tenant, owner and admin to have their own.
const ProfilePage = lazy(() => import('./pages/Profile'));

const ProtectedRoute = ({ children }) => {
  const { token, loading } = useAuth();
  if (loading) return <PageLoader />;
  if (!token) return <Navigate to="/login" replace />;
  return children;
};

const RoleRoute = ({ children, allowedRoles }) => {
  const { user, loading } = useAuth();
  if (loading) return <PageLoader />;
  if (!allowedRoles.includes(user?.role)) return <Navigate to="/" replace />;
  return children;
};

// Owner and tenant pages carry no chrome of their own, so they are mounted
// through the shared dashboard shell. This is what puts the notification
// bell (and the role sidebar) on those pages for every role.
const DashboardPage = ({ children, allowedRoles }) => (
  <ProtectedRoute>
    <RoleRoute allowedRoles={allowedRoles}>
      <DashboardLayout>{children}</DashboardLayout>
    </RoleRoute>
  </ProtectedRoute>
);

// Admin pages wrap themselves in DashboardLayout individually (see
// pages/Admin/Dashboard.jsx), so the admin profile is mounted the same way to
// keep the sidebar and the notification bell on it.
const AdminProfilePage = () => (
  <ProtectedRoute>
    <RoleRoute allowedRoles={['admin']}>
      <DashboardLayout>
        <ProfilePage />
      </DashboardLayout>
    </RoleRoute>
  </ProtectedRoute>
);

const AppRoutes = () => {
  return (
    <Suspense fallback={<PageLoader />}>
      <Routes>
        <Route path="/" element={<Home />} />
        <Route path="/login" element={<Login />} />
        <Route path="/register" element={<Register />} />
        <Route path="/auth/google/callback" element={<GoogleCallback />} />
        <Route path="/properties" element={<PropertyList />} />
        <Route path="/properties/:id" element={<PropertyDetail />} />

        <Route path="/tenant" element={<DashboardPage allowedRoles={['tenant']}><TenantDashboard /></DashboardPage>} />
        <Route path="/tenant/reservations" element={<DashboardPage allowedRoles={['tenant']}><TenantReservations /></DashboardPage>} />
        <Route path="/tenant/contracts" element={<DashboardPage allowedRoles={['tenant']}><TenantContracts /></DashboardPage>} />
        <Route path="/tenant/contracts/:id" element={<DashboardPage allowedRoles={['tenant']}><TenantContractDetail /></DashboardPage>} />
        <Route path="/tenant/payments" element={<DashboardPage allowedRoles={['tenant']}><TenantPayments /></DashboardPage>} />
        <Route path="/tenant/favorites" element={<DashboardPage allowedRoles={['tenant']}><TenantFavorites /></DashboardPage>} />
        <Route path="/tenant/profile" element={<DashboardPage allowedRoles={['tenant']}><ProfilePage /></DashboardPage>} />
        <Route path="/tenant/messages" element={<DashboardPage allowedRoles={['tenant']}><Messages /></DashboardPage>} />

        <Route path="/owner" element={<DashboardPage allowedRoles={['owner']}><OwnerDashboardPage /></DashboardPage>} />
        <Route path="/owner/properties" element={<DashboardPage allowedRoles={['owner']}><OwnerPropertiesPage /></DashboardPage>} />
        <Route path="/owner/properties/add" element={<DashboardPage allowedRoles={['owner']}><OwnerPropertyAddPage /></DashboardPage>} />
        <Route path="/owner/properties/:id/edit" element={<DashboardPage allowedRoles={['owner']}><OwnerPropertyEditPage /></DashboardPage>} />
        <Route path="/owner/reservations" element={<DashboardPage allowedRoles={['owner']}><OwnerReservationsPage /></DashboardPage>} />
        <Route path="/owner/reservations/:id" element={<DashboardPage allowedRoles={['owner']}><OwnerReservationDetailPage /></DashboardPage>} />
        <Route path="/owner/contracts" element={<DashboardPage allowedRoles={['owner']}><OwnerContractsPage /></DashboardPage>} />
        <Route path="/owner/contracts/:id" element={<DashboardPage allowedRoles={['owner']}><OwnerContractDetailPage /></DashboardPage>} />
        <Route path="/owner/payments" element={<DashboardPage allowedRoles={['owner']}><OwnerPaymentsPage /></DashboardPage>} />
        <Route path="/owner/profile" element={<DashboardPage allowedRoles={['owner']}><ProfilePage /></DashboardPage>} />
        <Route path="/owner/messages" element={<DashboardPage allowedRoles={['owner']}><Messages /></DashboardPage>} />

        <Route path="/admin" element={<ProtectedRoute><RoleRoute allowedRoles={['admin']}><AdminDashboardPage /></RoleRoute></ProtectedRoute>} />
        <Route path="/admin/users" element={<ProtectedRoute><RoleRoute allowedRoles={['admin']}><AdminUsersPage /></RoleRoute></ProtectedRoute>} />
        <Route path="/admin/users/:id" element={<ProtectedRoute><RoleRoute allowedRoles={['admin']}><AdminUserDetailPage /></RoleRoute></ProtectedRoute>} />
        <Route path="/admin/properties" element={<ProtectedRoute><RoleRoute allowedRoles={['admin']}><AdminPropertiesPage /></RoleRoute></ProtectedRoute>} />
        <Route path="/admin/properties/:id" element={<ProtectedRoute><RoleRoute allowedRoles={['admin']}><AdminPropertyDetailPage /></RoleRoute></ProtectedRoute>} />
        <Route path="/admin/reservations" element={<ProtectedRoute><RoleRoute allowedRoles={['admin']}><AdminReservationsPage /></RoleRoute></ProtectedRoute>} />
        <Route path="/admin/reservations/:id" element={<ProtectedRoute><RoleRoute allowedRoles={['admin']}><AdminReservationDetailPage /></RoleRoute></ProtectedRoute>} />
        <Route path="/admin/contracts" element={<ProtectedRoute><RoleRoute allowedRoles={['admin']}><AdminContractsPage /></RoleRoute></ProtectedRoute>} />
        <Route path="/admin/contracts/:id" element={<ProtectedRoute><RoleRoute allowedRoles={['admin']}><AdminContractDetailPage /></RoleRoute></ProtectedRoute>} />
        <Route path="/admin/payments" element={<ProtectedRoute><RoleRoute allowedRoles={['admin']}><AdminPaymentsPage /></RoleRoute></ProtectedRoute>} />
        <Route path="/admin/payments/:id" element={<ProtectedRoute><RoleRoute allowedRoles={['admin']}><AdminPaymentDetailPage /></RoleRoute></ProtectedRoute>} />
        <Route path="/admin/profile" element={<AdminProfilePage />} />

        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </Suspense>
  );
};

const App = () => {
  return (
    <AuthProvider>
      <FavoritesProvider>
        <AppRoutes />
        <Chatbot />
        <Toaster position="top-right" toastOptions={{ duration: 3000, style: { background: '#ffffff', color: '#111827', borderRadius: '12px', fontSize: '14px', fontWeight: 500, boxShadow: '0 12px 40px -12px rgba(15, 23, 42, 0.18), 0 0 0 1px rgba(229, 231, 235, 0.8)' }, success: { iconTheme: { primary: '#ff7e21', secondary: '#ffffff' } }, error: { iconTheme: { primary: '#ef4444', secondary: '#ffffff' } } }} />
      </FavoritesProvider>
    </AuthProvider>
  );
};

export default App;
