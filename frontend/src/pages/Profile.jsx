import { useState, useEffect, useRef } from 'react';
import { motion } from 'framer-motion';
import toast from 'react-hot-toast';
import {
  HiUser, HiEnvelope, HiPhone, HiCamera, HiArrowUpTray, HiBuildingOffice2,
  HiLockClosed, HiKey, HiEye, HiEyeSlash, HiIdentification, HiTrash,
} from 'react-icons/hi2';
import { getProfile, updateProfile, changePassword, deleteAvatar } from '../services/authService';
import { useAuth } from '../contexts/AuthContext';
import { getImageUrl, getInitials } from '../utils/getImageUrl';
import { Button } from '../components/ui/button';
import { Input } from '../components/ui/input';
import { Skeleton } from '../components/ui/skeleton';
import BrandLoader from '../components/common/BrandLoader';
import { ConfirmModal } from '../components/admin/AdminUI';

/** Matches ImageUploadService::PROFILE_MAX_BYTES on the server. */
const MAX_AVATAR_BYTES = 2 * 1024 * 1024;

const fieldErrors = (error) => error?.response?.data?.errors || {};

// Motion Variants
const containerVariants = {
  hidden: { opacity: 0 },
  visible: {
    opacity: 1,
    transition: {
      staggerChildren: 0.07,
      delayChildren: 0.05,
    },
  },
};

const sectionVariants = {
  hidden: { opacity: 0, y: 18, scale: 0.99 },
  visible: {
    opacity: 1,
    y: 0,
    scale: 1,
    transition: { type: 'spring', stiffness: 260, damping: 22 },
  },
};

const ProfileSkeleton = () => (
  <div className="space-y-6">
    <div className="flex items-center gap-3">
      <Skeleton className="h-7 w-1.5 rounded-full" />
      <div className="space-y-2">
        <Skeleton className="h-8 w-56 rounded-lg" />
        <Skeleton className="h-3.5 w-48 rounded-md" />
      </div>
    </div>
    <div className="rounded-2xl border border-slate-200/80 bg-white shadow-xs p-6 sm:p-8">
      <div className="flex items-center gap-6 mb-8">
        <Skeleton className="w-24 h-24 rounded-2xl" />
        <div className="space-y-3">
          <Skeleton className="h-5 w-40 rounded-md" />
          <Skeleton className="h-4 w-32 rounded-md" />
        </div>
      </div>
      <div className="space-y-5">
        {[...Array(3)].map((_, i) => (
          <div key={i} className="space-y-2">
            <Skeleton className="h-3 w-24 rounded-md" />
            <Skeleton className="h-11 w-full rounded-xl" />
          </div>
        ))}
      </div>
    </div>
  </div>
);

/**
 * The account settings page, shared by tenants, owners and admins.
 *
 * Everything here edits the signed-in user only. The email is shown but not
 * editable, and the API ignores it, so the page never sends it.
 */
const Profile = () => {
  const { user, isOwner, refreshUser } = useAuth();
  const fileInputRef = useRef(null);

  const [form, setForm] = useState({ full_name: '', email: '', phone: '', company_name: '' });
  const [avatarDataUri, setAvatarDataUri] = useState(null);
  const [currentAvatar, setCurrentAvatar] = useState(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState({});

  const [passwords, setPasswords] = useState({ current_password: '', password: '', password_confirmation: '' });
  const [passwordVisible, setPasswordVisible] = useState(false);
  const [passwordSaving, setPasswordSaving] = useState(false);
  const [passwordErrors, setPasswordErrors] = useState({});

  // An account that only ever signs in with Google has no password yet, so there
  // is no current password to be asked for; this is where it gets its first one.
  // Anything else keeps asking, exactly as before.
  const [hasPassword, setHasPassword] = useState(true);

  const [avatarDeleteOpen, setAvatarDeleteOpen] = useState(false);
  const [deletingAvatar, setDeletingAvatar] = useState(false);

  // The session user is enough to render, so a failed profile read leaves the
  // form usable instead of showing an empty page.
  const fillFrom = (profile) => {
    setForm({
      full_name: profile?.full_name ?? '',
      email: profile?.email ?? '',
      phone: profile?.phone ?? '',
      company_name: profile?.company_name ?? '',
    });
    setCurrentAvatar(profile?.avatar || null);
    // Unknown means "assume there is one": asking for a password that does exist
    // is a wrong field, dropping it for an account that has one is a lockout.
    setHasPassword(profile?.has_password ?? true);
  };

  useEffect(() => {
    let cancelled = false;

    getProfile()
      .then((response) => {
        if (!cancelled) fillFrom(response.data?.data || response.data);
      })
      .catch(() => {
        if (!cancelled) fillFrom(user);
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
    // Runs once: the profile is the source of truth for this form, and
    // `user` changing must not overwrite what is being typed.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const handleChange = (e) => {
    const { name, value } = e.target;
    setForm((previous) => ({ ...previous, [name]: value }));
    setErrors((previous) => ({ ...previous, [name]: undefined }));
  };

  const handleAvatarChange = (e) => {
    const file = e.target.files?.[0];
    // Allow re-picking the same file right after an upload.
    if (e.target) e.target.value = '';
    if (!file) return;

    if (!file.type.startsWith('image/')) {
      toast.error('Please choose an image file');
      return;
    }

    if (file.size > MAX_AVATAR_BYTES) {
      toast.error('The picture must be smaller than 2MB');
      return;
    }

    const reader = new FileReader();
    reader.onerror = () => toast.error('That image could not be read');
    reader.onloadend = () => setAvatarDataUri(reader.result);
    reader.readAsDataURL(file);
  };

  const handleAvatarDelete = async () => {
    setDeletingAvatar(true);

    try {
      const response = await deleteAvatar();

      // The stored picture is gone, so any picture still waiting to be saved
      // goes with it: the page has to agree with what the server now holds.
      setAvatarDataUri(null);
      fillFrom(response.data.user);
      // The navbar and the sidebar show the same avatar, so they refresh too.
      await refreshUser();

      setAvatarDeleteOpen(false);
      toast.success(response.data?.message || 'Profile picture removed successfully');
    } catch (error) {
      toast.error(
        fieldErrors(error).avatar?.[0] ||
          error?.response?.data?.message ||
          'Failed to remove profile picture'
      );
    } finally {
      setDeletingAvatar(false);
    }
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSaving(true);
    setErrors({});

    try {
      // Images travel as a data URI in the JSON body, the same way property
      // photos are uploaded, so no multipart handling is needed here.
      const payload = {
        full_name: form.full_name,
        phone: form.phone,
      };

      if (isOwner) payload.company_name = form.company_name;
      if (avatarDataUri) payload.avatar = avatarDataUri;

      const response = await updateProfile(payload);

      // The avatar is now a stored file, so the data URI has done its job.
      setAvatarDataUri(null);
      fillFrom(response.data.user);
      // The session user drives the navbar, the sidebar and every other place
      // the name and picture appear, so it has to be refreshed too.
      await refreshUser();

      toast.success('Profile updated successfully');
    } catch (error) {
      const mapped = fieldErrors(error);
      setErrors(mapped);
      toast.error(error?.response?.data?.message || 'Failed to update profile');
    } finally {
      setSaving(false);
    }
  };

  const handlePasswordSubmit = async (e) => {
    e.preventDefault();

    // Client-side check so a mismatch never reaches the API; the backend
    // keeps validating this too.
    if (passwords.password !== passwords.password_confirmation) {
      setPasswordErrors({ password_confirmation: ['Passwords do not match.'] });
      toast.error('Passwords do not match.');
      return;
    }

    setPasswordSaving(true);
    setPasswordErrors({});

    try {
      await changePassword(passwords);
      setPasswords({ current_password: '', password: '', password_confirmation: '' });
      // The account has a password from now on, so the form asks for it next time.
      setHasPassword(true);
      toast.success('Password changed successfully');
    } catch (error) {
      const mapped = fieldErrors(error);
      setPasswordErrors(mapped);
      toast.error(error?.response?.data?.message || 'Failed to change password');
    } finally {
      setPasswordSaving(false);
    }
  };

  const preview = avatarDataUri || getImageUrl(currentAvatar);
  const initials = getInitials(form.full_name || user?.full_name);

  if (loading) {
    return (
      <div className="p-4 sm:p-6 lg:p-8 max-w-4xl mx-auto antialiased selection:bg-orange-500 selection:text-white">
        <ProfileSkeleton />
      </div>
    );
  }

  return (
    <motion.div
      initial={{ opacity: 0, y: 12 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.35, ease: 'easeOut' }}
      className="p-4 sm:p-6 lg:p-8 max-w-4xl mx-auto space-y-6 antialiased selection:bg-orange-500 selection:text-white"
    >
      {/* Header Section */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-2 border-b border-slate-200/60">
        <div className="relative">
          <div className="flex items-center gap-3">
            {/* Orange Gradient Accent Bar */}
            <div className="h-7 w-1.5 rounded-full bg-gradient-to-b from-orange-500 to-amber-500 shadow-sm" />

            <div className="flex items-center gap-3 flex-wrap">
              <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900 font-display">
                Profile Settings
              </h1>

              {/* Role Badge */}
              <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-orange-50 border border-orange-200/80 text-orange-700 capitalize shadow-2xs">
                <span className="relative flex h-2 w-2">
                  <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-orange-400 opacity-75"></span>
                  <span className="relative inline-flex rounded-full h-2 w-2 bg-orange-500"></span>
                </span>
                {user?.role || 'account'}
              </span>
            </div>
          </div>

          <p className="text-sm text-slate-500 mt-1.5 ml-4.5 font-normal leading-relaxed">
            Manage your account information
          </p>
        </div>
      </div>

      <motion.div
        variants={containerVariants}
        initial="hidden"
        animate="visible"
        className="space-y-6"
      >
        {/* Account Information */}
        <motion.form
          onSubmit={handleSubmit}
          variants={sectionVariants}
          className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-5 sm:p-6 space-y-6"
        >
          <div className="flex items-center gap-3 pb-4 border-b border-slate-100">
            <div className="w-10 h-10 rounded-xl bg-orange-50 text-orange-600 border border-orange-100 flex items-center justify-center shrink-0 shadow-xs">
              <HiIdentification className="w-5 h-5" />
            </div>
            <div className="min-w-0">
              <h2 className="text-base font-bold text-slate-900 tracking-tight">
                Account Information
              </h2>
              <p className="text-xs text-slate-500 mt-0.5">How you appear across the platform.</p>
            </div>
          </div>

          <div className="flex flex-col sm:flex-row items-start sm:items-center gap-6">
            <div className="relative group shrink-0">
              <div className="w-24 h-24 rounded-2xl bg-slate-900 border border-slate-800 flex items-center justify-center overflow-hidden shadow-xs">
                {preview ? (
                  <img src={preview} alt="" className="w-full h-full object-cover" />
                ) : (
                  <span className="text-3xl font-bold text-orange-400 font-display">{initials}</span>
                )}
              </div>
              <Button
                type="button"
                size="icon-sm"
                onClick={() => fileInputRef.current?.click()}
                aria-label="Change profile picture"
                className="absolute bottom-0 right-0 h-9 w-9 rounded-xl bg-orange-500 hover:bg-orange-600 text-white border-2 border-white shadow-md opacity-0 group-hover:opacity-100 focus-visible:opacity-100 transition-all duration-200 active:scale-[0.98] cursor-pointer"
              >
                <HiCamera className="w-4 h-4 stroke-[2.5]" />
              </Button>
              <input
                ref={fileInputRef}
                type="file"
                accept="image/png,image/jpeg,image/webp,image/gif"
                onChange={handleAvatarChange}
                className="hidden"
              />
            </div>
            <div className="min-w-0">
              <p className="font-bold text-slate-900 text-lg tracking-tight">
                {form.full_name || 'Your Name'}
              </p>
              <p className="text-sm text-slate-500 mt-0.5">{form.email}</p>
              {avatarDataUri && (
                <span className="inline-flex items-center gap-1.5 mt-2.5 px-3 py-1 rounded-full text-xs font-semibold bg-orange-50 border border-orange-200/80 text-orange-700">
                  New picture — save to apply
                </span>
              )}
              <div className="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2.5">
                <Button
                  type="button"
                  variant="link"
                  size="sm"
                  onClick={() => fileInputRef.current?.click()}
                  className="px-0 h-auto text-xs font-semibold text-orange-600 hover:text-orange-700 hover:no-underline no-underline transition-colors duration-200 active:scale-[0.98] cursor-pointer"
                >
                  <HiCamera className="w-3.5 h-3.5" />
                  Change photo
                </Button>
                {currentAvatar && (
                  <Button
                    type="button"
                    variant="link"
                    size="sm"
                    onClick={() => setAvatarDeleteOpen(true)}
                    className="px-0 h-auto text-xs font-semibold text-slate-400 hover:text-rose-600 hover:no-underline no-underline transition-colors duration-200 active:scale-[0.98] cursor-pointer"
                  >
                    <HiTrash className="w-3.5 h-3.5" />
                    Delete photo
                  </Button>
                )}
              </div>
            </div>
          </div>

          <div className="border-t border-slate-100 pt-6 space-y-5">
            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 mb-1.5">
                Full Name
              </label>
              <div className="relative group">
                <HiUser className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 group-focus-within:text-orange-500 transition-colors pointer-events-none" />
                <Input
                  type="text"
                  name="full_name"
                  value={form.full_name}
                  onChange={handleChange}
                  maxLength={100}
                  required
                  placeholder="Your full name"
                  className="pl-10 h-11 text-sm bg-white border-slate-200/90 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-500/15 transition-all placeholder:text-slate-400 text-slate-900 shadow-xs"
                  aria-invalid={Boolean(errors.full_name)}
                />
              </div>
              {errors.full_name && (
                <p className="text-xs font-medium text-rose-600 mt-1.5">{errors.full_name[0]}</p>
              )}
            </div>

            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 mb-1.5">
                Email Address
              </label>
              <div className="relative">
                <HiEnvelope className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none" />
                <Input
                  type="email"
                  value={form.email}
                  readOnly
                  className="pl-10 h-11 text-sm bg-slate-50 border-slate-200/90 rounded-xl text-slate-500 cursor-not-allowed shadow-xs transition-all"
                />
              </div>
              <p className="text-xs text-slate-400 mt-1.5">Email cannot be changed</p>
            </div>

            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 mb-1.5">
                Phone Number
              </label>
              <div className="relative group">
                <HiPhone className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 group-focus-within:text-orange-500 transition-colors pointer-events-none" />
                <Input
                  type="tel"
                  name="phone"
                  value={form.phone}
                  onChange={handleChange}
                  maxLength={20}
                  placeholder="+212 6 00 00 00 00"
                  className="pl-10 h-11 text-sm bg-white border-slate-200/90 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-500/15 transition-all placeholder:text-slate-400 text-slate-900 shadow-xs"
                  aria-invalid={Boolean(errors.phone)}
                />
              </div>
              {errors.phone && (
                <p className="text-xs font-medium text-rose-600 mt-1.5">{errors.phone[0]}</p>
              )}
            </div>

            {isOwner && (
              <div>
                <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 mb-1.5">
                  Company Name
                </label>
                <div className="relative group">
                  <HiBuildingOffice2 className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 group-focus-within:text-orange-500 transition-colors pointer-events-none" />
                  <Input
                    type="text"
                    name="company_name"
                    value={form.company_name}
                    onChange={handleChange}
                    maxLength={150}
                    placeholder="Your company name (optional)"
                    className="pl-10 h-11 text-sm bg-white border-slate-200/90 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-500/15 transition-all placeholder:text-slate-400 text-slate-900 shadow-xs"
                    aria-invalid={Boolean(errors.company_name)}
                  />
                </div>
                {errors.company_name && (
                  <p className="text-xs font-medium text-rose-600 mt-1.5">
                    {errors.company_name[0]}
                  </p>
                )}
              </div>
            )}
          </div>

          {errors.avatar && (
            <p className="text-xs font-medium text-rose-600">{errors.avatar[0]}</p>
          )}

          <div className="flex justify-end pt-1">
            <Button
              type="submit"
              disabled={saving}
              className="h-11 px-5 bg-slate-900 hover:bg-slate-800 text-white font-semibold text-sm rounded-xl shadow-sm hover:shadow-md active:scale-[0.98] transition-all duration-200 border border-slate-800 no-underline hover:no-underline cursor-pointer"
            >
              {saving ? (
                <div className="flex items-center justify-center gap-2">
                  <BrandLoader size="small" showText={false} />
                  <span>Saving...</span>
                </div>
              ) : (
                <>
                  <HiArrowUpTray className="w-5 h-5 text-orange-500 stroke-[2.5]" />
                  <span>Save Changes</span>
                </>
              )}
            </Button>
          </div>
        </motion.form>

        {/* Password */}
        <motion.form
          onSubmit={handlePasswordSubmit}
          variants={sectionVariants}
          className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-5 sm:p-6 space-y-6"
        >
          <div className="flex items-center gap-3 pb-4 border-b border-slate-100">
            <div className="w-10 h-10 rounded-xl bg-orange-50 text-orange-600 border border-orange-100 flex items-center justify-center shrink-0 shadow-xs">
              <HiKey className="w-5 h-5" />
            </div>
            <div className="min-w-0">
              <h2 className="text-base font-bold text-slate-900 tracking-tight">Password</h2>
              <p className="text-xs text-slate-500 mt-0.5">
                {hasPassword
                  ? 'Changing your password signs you out of every other device.'
                  : 'You signed in with Google. Set a password to also sign in with your email.'}
              </p>
            </div>
          </div>

          <div className="space-y-5">
            {hasPassword && (
              <div>
                <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 mb-1.5">
                  Current Password
                </label>
                <div className="relative group">
                  <HiLockClosed className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 group-focus-within:text-orange-500 transition-colors pointer-events-none" />
                  <Input
                    type={passwordVisible ? 'text' : 'password'}
                    name="current_password"
                    value={passwords.current_password}
                    onChange={(e) => {
                      setPasswords((p) => ({ ...p, current_password: e.target.value }));
                      setPasswordErrors((p) => ({ ...p, current_password: undefined }));
                    }}
                    autoComplete="current-password"
                    required
                    placeholder="Your current password"
                    className="pl-10 pr-11 h-11 text-sm bg-white border-slate-200/90 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-500/15 transition-all placeholder:text-slate-400 text-slate-900 shadow-xs"
                    aria-invalid={Boolean(passwordErrors.current_password)}
                  />
                </div>
                {passwordErrors.current_password && (
                  <p className="text-xs font-medium text-rose-600 mt-1.5">
                    {passwordErrors.current_password[0]}
                  </p>
                )}
              </div>
            )}

            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 mb-1.5">
                New Password
              </label>
              <div className="relative group">
                <HiLockClosed className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 group-focus-within:text-orange-500 transition-colors pointer-events-none" />
                <Input
                  type={passwordVisible ? 'text' : 'password'}
                  name="password"
                  value={passwords.password}
                  onChange={(e) => {
                    setPasswords((p) => ({ ...p, password: e.target.value }));
                    setPasswordErrors((p) => ({ ...p, password: undefined, password_confirmation: undefined }));
                  }}
                  autoComplete="new-password"
                  minLength={8}
                  required
                  placeholder="At least 8 characters"
                  className="pl-10 pr-11 h-11 text-sm bg-white border-slate-200/90 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-500/15 transition-all placeholder:text-slate-400 text-slate-900 shadow-xs"
                  aria-invalid={Boolean(passwordErrors.password)}
                />
                <button
                  type="button"
                  onClick={() => setPasswordVisible((v) => !v)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100 transition-colors duration-200 active:scale-[0.98] cursor-pointer"
                  aria-label={passwordVisible ? 'Hide password' : 'Show password'}
                >
                  {passwordVisible ? <HiEyeSlash className="w-4 h-4" /> : <HiEye className="w-4 h-4" />}
                </button>
              </div>
              {passwordErrors.password && (
                <p className="text-xs font-medium text-rose-600 mt-1.5">
                  {passwordErrors.password[0]}
                </p>
              )}
            </div>

            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 mb-1.5">
                Confirm New Password
              </label>
              <div className="relative group">
                <HiLockClosed className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 group-focus-within:text-orange-500 transition-colors pointer-events-none" />
                <Input
                  type={passwordVisible ? 'text' : 'password'}
                  name="password_confirmation"
                  value={passwords.password_confirmation}
                  onChange={(e) => {
                    setPasswords((p) => ({ ...p, password_confirmation: e.target.value }));
                    setPasswordErrors((p) => ({ ...p, password: undefined, password_confirmation: undefined }));
                  }}
                  autoComplete="new-password"
                  minLength={8}
                  required
                  placeholder="Repeat the new password"
                  className="pl-10 pr-11 h-11 text-sm bg-white border-slate-200/90 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-500/15 transition-all placeholder:text-slate-400 text-slate-900 shadow-xs"
                  aria-invalid={Boolean(passwordErrors.password_confirmation)}
                />
              </div>
              {passwordErrors.password_confirmation && (
                <p className="text-xs font-medium text-rose-600 mt-1.5">
                  {passwordErrors.password_confirmation[0]}
                </p>
              )}
            </div>
          </div>

          <div className="flex justify-end pt-1">
            <Button
              type="submit"
              variant="outline"
              disabled={passwordSaving}
              className="h-11 px-5 rounded-xl border-slate-200 hover:bg-slate-50 hover:border-slate-300 font-semibold text-slate-700 cursor-pointer"
            >
              {passwordSaving ? (
                <div className="flex items-center justify-center gap-2">
                  <div className="animate-spin rounded-full h-4 w-4 border-2 border-slate-300 border-t-orange-500" />
                  <span>Updating...</span>
                </div>
              ) : (
                <>
                  <HiKey className="w-4 h-4 text-slate-500" />
                  <span>Change Password</span>
                </>
              )}
            </Button>
          </div>
        </motion.form>
      </motion.div>

      <ConfirmModal
        isOpen={avatarDeleteOpen}
        title="Delete profile picture"
        message="Are you sure you want to remove your profile picture? Your initials will be shown instead. This action cannot be undone."
        confirmLabel="Delete"
        cancelLabel="Cancel"
        onConfirm={handleAvatarDelete}
        onCancel={() => setAvatarDeleteOpen(false)}
        loading={deletingAvatar}
        variant="danger"
        icon={HiTrash}
      />
    </motion.div>
  );
};

export default Profile;