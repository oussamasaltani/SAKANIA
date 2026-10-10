import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import DashboardLayout from '../../components/layouts/DashboardLayout';
import { getUser, activateUser, deactivateUser, deleteUser } from '../../services/adminService';
import toast from 'react-hot-toast';
import {
  HiNoSymbol,
  HiCalendarDays,
  HiCheck,
  HiDevicePhoneMobile,
  HiEnvelope,
  HiIdentification,
  HiShieldCheck,
  HiSignal,
  HiTrash,
  HiUser,
} from 'react-icons/hi2';
import { Button } from '../../components/ui/button';
import UserAvatar from '../../components/common/UserAvatar';
import {
  AdminPage,
  ConfirmModal,
  DetailHeader,
  DetailSkeleton,
  InfoRow,
  RoleBadge,
  SectionCard,
  Spinner,
  StatusBadge,
  formatDate,
} from '../../components/admin/AdminUI';

const USER_STATUS_MAP = { active: 'emerald', inactive: 'rose' };

const UserDetail = () => {
  const { id } = useParams();
  const navigate = useNavigate();
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [processing, setProcessing] = useState(false);
  const [deleteModal, setDeleteModal] = useState(false);
  const [deleting, setDeleting] = useState(false);

  useEffect(() => {
    getUser(id)
      .then((res) => {
        const data = res.data?.data || res.data;
        setUser(data ? { ...data, status: data.is_active ? 'active' : 'inactive' } : null);
      })
      .catch(() => {
        toast.error('Failed to load user');
        navigate('/admin/users');
      })
      .finally(() => setLoading(false));
  }, [id, navigate]);

  const handleToggleStatus = async () => {
    if (!user) return;
    setProcessing(true);
    try {
      if (user.status === 'active') {
        await deactivateUser(user.id);
        toast.success('User deactivated');
        setUser((prev) => ({ ...prev, status: 'inactive' }));
      } else {
        await activateUser(user.id);
        toast.success('User activated');
        setUser((prev) => ({ ...prev, status: 'active' }));
      }
    } catch (err) {
      toast.error(err.response?.data?.message || 'Action failed');
    } finally {
      setProcessing(false);
    }
  };

  const handleDelete = async () => {
    if (!user) return;
    setDeleting(true);
    try {
      await deleteUser(user.id);
      toast.success('User deleted');
      navigate('/admin/users');
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

  if (!user) return null;

  const displayName = user.full_name || user.name || 'Unknown user';
  const isActive = user.status === 'active';
  const role = user.role?.name || user.role;

  return (
    <DashboardLayout>
      <AdminPage className="max-w-5xl">
        <DetailHeader
          title="User Profile"
          subtitle={`Account #${user.id} · ${displayName}`}
          onBack={() => navigate('/admin/users')}
        >
          <div className="flex items-center gap-2 shrink-0">
            <RoleBadge role={role} icon={HiShieldCheck} />
            <StatusBadge status={user.status} map={USER_STATUS_MAP} />
          </div>
        </DetailHeader>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div className="lg:col-span-2 space-y-6">
            <SectionCard>
              <div className="flex flex-col sm:flex-row items-start gap-5 pb-6 border-b border-slate-100">
                <div className="w-20 h-20 rounded-2xl bg-slate-100 border border-slate-200/60 flex items-center justify-center overflow-hidden shrink-0 shadow-2xs">
                  <UserAvatar user={user} className="w-full h-full text-slate-500 text-xl font-bold" />
                </div>
                <div className="min-w-0">
                  <h2 className="text-lg font-extrabold tracking-tight text-slate-900">{displayName}</h2>
                  <p className="text-sm text-slate-500 mt-1 flex items-center gap-1.5">
                    <HiEnvelope className="w-4 h-4 text-slate-400" />
                    {user.email || '—'}
                  </p>
                  <div className="flex items-center gap-2 mt-3 flex-wrap">
                    <RoleBadge role={role} icon={HiShieldCheck} />
                    <StatusBadge status={user.status} map={USER_STATUS_MAP} />
                  </div>
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-6">
                <InfoRow icon={HiUser} label="Full Name" value={user.full_name || user.name} />
                <InfoRow icon={HiEnvelope} label="Email" value={user.email} />
                <InfoRow icon={HiDevicePhoneMobile} label="Phone" value={user.phone} />
                <InfoRow icon={HiShieldCheck} label="Role" value={role} />
                <InfoRow icon={HiSignal} label="Status" value={user.status || 'unknown'} />
                <InfoRow icon={HiCalendarDays} label="Joined" value={formatDate(user.created_at, null)} />
              </div>
            </SectionCard>
          </div>

          <div className="space-y-6">
            <SectionCard title="Account Status" icon={HiSignal}>
              <p className="text-sm text-slate-500 leading-relaxed">
                {isActive
                  ? 'This account is active and can sign in and transact on the platform.'
                  : 'This account is suspended and cannot sign in.'}
              </p>
              <Button
                onClick={handleToggleStatus}
                disabled={processing}
                className={`w-full mt-5 rounded-xl ${
                  isActive ? 'bg-amber-600 hover:bg-amber-700' : 'bg-emerald-600 hover:bg-emerald-700'
                } text-white`}
              >
                {processing ? (
                  <Spinner />
                ) : isActive ? (
                  <HiNoSymbol className="w-4 h-4" />
                ) : (
                  <HiCheck className="w-4 h-4" />
                )}
                {isActive ? 'Deactivate User' : 'Activate User'}
              </Button>
            </SectionCard>

            <SectionCard title="Danger Zone" icon={HiIdentification}>
              <p className="text-sm text-slate-500 leading-relaxed">
                Deleting an account is permanent and removes every record attached to it.
              </p>
              <Button onClick={() => setDeleteModal(true)} variant="destructive" className="w-full mt-5 rounded-xl">
                <HiTrash className="w-4 h-4" /> Delete User
              </Button>
            </SectionCard>
          </div>
        </div>

        <ConfirmModal
          isOpen={deleteModal}
          title="Delete User"
          message={`Are you sure you want to delete "${displayName}"? This action cannot be undone.`}
          confirmLabel="Delete"
          onConfirm={handleDelete}
          onCancel={() => setDeleteModal(false)}
          loading={deleting}
        />
      </AdminPage>
    </DashboardLayout>
  );
};

export default UserDetail;