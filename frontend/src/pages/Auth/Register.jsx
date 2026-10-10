import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../../contexts/AuthContext';
import toast from 'react-hot-toast';
import { HiUser, HiMail, HiLockClosed, HiEye, HiEyeOff, HiCheck } from 'react-icons/hi';
import { motion } from 'framer-motion';
import PublicNavbar from '../../components/common/PublicNavbar';
import Footer from '../../components/common/Footer';
import GoogleAuthButton from '../../components/common/GoogleAuthButton';
import useGoogleSignInNotice from '../../components/common/useGoogleSignInNotice';
import BrandLoader from '../../components/common/BrandLoader';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';

/** Same mapping the form below uses, so both ways in pick the same role. */
const roleMap = { tenant: 1, owner: 2 };

const passwordRequirements = [
  { label: 'At least 8 characters', test: (p) => p.length >= 8 },
  { label: 'One uppercase letter', test: (p) => /[A-Z]/.test(p) },
  { label: 'One lowercase letter', test: (p) => /[a-z]/.test(p) },
  { label: 'One number', test: (p) => /\d/.test(p) },
  { label: 'One special character', test: (p) => /[^A-Za-z0-9]/.test(p) },
];

const getStrength = (password) => {
  const passed = passwordRequirements.filter((r) => r.test(password)).length;
  if (passed <= 1) return { level: 0, label: 'Very Weak', color: 'bg-red-500', width: '20%' };
  if (passed === 2) return { level: 1, label: 'Weak', color: 'bg-orange-500', width: '40%' };
  if (passed === 3) return { level: 2, label: 'Fair', color: 'bg-yellow-500', width: '60%' };
  if (passed === 4) return { level: 3, label: 'Strong', color: 'bg-blue-500', width: '80%' };
  return { level: 4, label: 'Very Strong', color: 'bg-green-500', width: '100%' };
};

const Register = () => {
  const { register } = useAuth();
  const navigate = useNavigate();
  const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '', role: 'tenant' });
  const [loading, setLoading] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirm, setShowConfirm] = useState(false);

  // Explains a Google sign-in the API turned away, when it sends the user back
  // here with ?google=... instead of a session.
  useGoogleSignInNotice();

  const handleChange = (e) => {
    setForm({ ...form, [e.target.name]: e.target.value });
  };

  const strength = getStrength(form.password);

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (form.password !== form.password_confirmation) {
      toast.error('Passwords do not match');
      return;
    }
    setLoading(true);
    try {
      await register({
        full_name: form.name,
        email: form.email,
        password: form.password,
        password_confirmation: form.password_confirmation,
        role_id: roleMap[form.role],
      });
      toast.success('Account created successfully!');
      const role = form.role;
      if (role === 'owner') navigate('/owner');
      else navigate('/tenant');
    } catch (err) {
      toast.error(err.response?.data?.message || 'Registration failed');
    } finally {
      setLoading(false);
    }
  };

  const roleOptions = [
    { value: 'tenant', label: 'Find a Home', desc: 'Browse & rent properties' },
    { value: 'owner', label: 'Rent Out', desc: 'List your properties' },
  ];

  const inputClass =
    'h-11 text-sm bg-slate-50/50 border-slate-200 rounded-xl focus:bg-white focus:border-orange-500 focus:ring-2 focus:ring-orange-500/15 transition-all placeholder:text-slate-400';
  const labelClass = 'block text-xs font-semibold uppercase tracking-wider text-slate-700 mb-1.5';
  const iconClass =
    'absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 group-focus-within:text-orange-500 transition-colors pointer-events-none';

  return (
    <div className="min-h-screen flex flex-col bg-slate-50/70 antialiased selection:bg-orange-500 selection:text-white">
      <PublicNavbar />

      <main className="flex-1 flex items-center justify-center px-4 py-10 sm:px-6 lg:px-8">
        <motion.div
          initial={{ opacity: 0, y: 10 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.25, ease: 'easeOut' }}
          className="w-full max-w-[420px] bg-white rounded-2xl border border-slate-200/80 shadow-[0_4px_25px_-5px_rgba(0,0,0,0.06)] p-6 sm:p-8"
        >
          {/* Header section - Inside Card */}
          <div className="text-center mb-6">
            <Link
              to="/"
              className="inline-block focus:outline-none focus:ring-2 focus:ring-orange-500/20 rounded-xl transition-all"
            >
              <img
                src="/Logo-sakania.png"
                alt="SAKANIA"
                className="h-14 md:h-16 w-auto mx-auto object-contain hover:opacity-95 transition-opacity"
              />
            </Link>
            <h1 className="mt-4 text-2xl font-bold tracking-tight text-slate-900 font-display">
              Create your account
            </h1>
            <p className="mt-1 text-sm text-slate-500">
              Join SAKANIA and find your perfect home
            </p>
          </div>

          {/* Form */}
          <form onSubmit={handleSubmit} className="space-y-4">
            {/* Full Name Input */}
            <div>
              <label htmlFor="name" className={labelClass}>
                Full Name
              </label>
              <div className="relative group">
                <HiUser className={iconClass} />
                <Input
                  id="name"
                  type="text"
                  name="name"
                  autoComplete="name"
                  value={form.name}
                  onChange={handleChange}
                  required
                  placeholder="Mohamed Alaoui"
                  className={`pl-10 ${inputClass}`}
                />
              </div>
            </div>

            {/* Email Input */}
            <div>
              <label htmlFor="email" className={labelClass}>
                Email Address
              </label>
              <div className="relative group">
                <HiMail className={iconClass} />
                <Input
                  id="email"
                  type="email"
                  name="email"
                  autoComplete="email"
                  value={form.email}
                  onChange={handleChange}
                  required
                  placeholder="name@example.com"
                  className={`pl-10 ${inputClass}`}
                />
              </div>
            </div>

            {/* Password Input */}
            <div>
              <label htmlFor="password" className={labelClass}>
                Password
              </label>
              <div className="relative group">
                <HiLockClosed className={iconClass} />
                <Input
                  id="password"
                  type={showPassword ? 'text' : 'password'}
                  name="password"
                  autoComplete="new-password"
                  value={form.password}
                  onChange={handleChange}
                  required
                  placeholder="••••••••"
                  className={`pl-10 pr-10 ${inputClass}`}
                />
                <button
                  type="button"
                  tabIndex={-1}
                  aria-label={showPassword ? 'Hide password' : 'Show password'}
                  onClick={() => setShowPassword(!showPassword)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 focus:outline-none transition-colors p-1"
                >
                  {showPassword ? <HiEyeOff className="w-4 h-4" /> : <HiEye className="w-4 h-4" />}
                </button>
              </div>

              {/* Password strength */}
              {form.password && (
                <div className="mt-3 space-y-2.5">
                  <div className="flex items-center gap-2.5">
                    <div className="flex-1 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                      <div
                        className={`h-full rounded-full transition-all duration-300 ${strength.color}`}
                        style={{ width: strength.width }}
                      />
                    </div>
                    <span className="text-xs font-semibold text-slate-600 w-20 text-right">
                      {strength.label}
                    </span>
                  </div>
                  <div className="grid grid-cols-1 gap-y-1.5">
                    {passwordRequirements.map((req) => {
                      const passed = req.test(form.password);
                      return (
                        <div key={req.label} className="flex items-center gap-2">
                          {passed ? (
                            <HiCheck className="w-3.5 h-3.5 shrink-0 text-emerald-500" />
                          ) : (
                            <div className="w-3.5 h-3.5 shrink-0 rounded-full border border-slate-300" />
                          )}
                          <span
                            className={`text-xs transition-colors ${
                              passed ? 'text-slate-700 font-medium' : 'text-slate-400'
                            }`}
                          >
                            {req.label}
                          </span>
                        </div>
                      );
                    })}
                  </div>
                </div>
              )}
            </div>

            {/* Confirm Password Input */}
            <div>
              <label htmlFor="password_confirmation" className={labelClass}>
                Confirm Password
              </label>
              <div className="relative group">
                <HiLockClosed className={iconClass} />
                <Input
                  id="password_confirmation"
                  type={showConfirm ? 'text' : 'password'}
                  name="password_confirmation"
                  autoComplete="new-password"
                  value={form.password_confirmation}
                  onChange={handleChange}
                  required
                  placeholder="••••••••"
                  className={`pl-10 pr-10 ${inputClass}`}
                />
                <button
                  type="button"
                  tabIndex={-1}
                  aria-label={showConfirm ? 'Hide password confirmation' : 'Show password confirmation'}
                  onClick={() => setShowConfirm(!showConfirm)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 focus:outline-none transition-colors p-1"
                >
                  {showConfirm ? <HiEyeOff className="w-4 h-4" /> : <HiEye className="w-4 h-4" />}
                </button>
              </div>
            </div>

            {/* Role Selection */}
            <div>
              <span className={labelClass}>I want to</span>
              <div className="grid grid-cols-2 gap-3">
                {roleOptions.map((opt) => {
                  const active = form.role === opt.value;
                  return (
                    <button
                      key={opt.value}
                      type="button"
                      aria-pressed={active}
                      onClick={() => setForm({ ...form, role: opt.value })}
                      className={`p-3.5 rounded-xl border text-left transition-all duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-500/30 ${
                        active
                          ? 'border-orange-500 bg-orange-50/60 shadow-md shadow-orange-500/10'
                          : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
                      }`}
                    >
                      <p
                        className={`text-sm font-semibold transition-colors ${
                          active ? 'text-orange-600' : 'text-slate-900'
                        }`}
                      >
                        {opt.label}
                      </p>
                      <p className="text-xs text-slate-500 mt-0.5 leading-relaxed">{opt.desc}</p>
                    </button>
                  );
                })}
              </div>
            </div>

            {/* Submit Button */}
            <Button
              type="submit"
              disabled={loading}
              className="w-full h-11 bg-gradient-to-r from-orange-500 to-orange-600 hover:from-orange-600 hover:to-orange-700 text-white font-medium text-sm rounded-xl shadow-md hover:shadow-lg hover:shadow-orange-500/25 active:translate-y-0 active:scale-[0.98] transition-all duration-200 mt-2 cursor-pointer"
            >
              {loading ? (
                <div className="flex items-center justify-center gap-2">
                  <BrandLoader size="small" showText={false} />
                  <span>Creating account...</span>
                </div>
              ) : (
                'Create Account'
              )}
            </Button>
          </form>

          {/* Google sign-up: the account is created from the Google profile and
              signed in, taking the role chosen above. */}
          <GoogleAuthButton roleId={roleMap[form.role]} returnTo="register" />

          {/* Login link */}
          <div className="mt-6 pt-5 border-t border-slate-100 text-center">
            <p className="text-xs text-slate-500">
              Already have an account?{' '}
              <Link
                to="/login"
                className="font-semibold text-orange-600 hover:text-orange-700 hover:underline transition-all"
              >
                Sign in
              </Link>
            </p>
          </div>
        </motion.div>
      </main>

      <Footer />
    </div>
  );
};

export default Register;