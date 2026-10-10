import { useEffect, useRef, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { motion } from 'framer-motion';
import toast from 'react-hot-toast';
import { useAuth } from '../../contexts/AuthContext';
import PublicNavbar from '../../components/common/PublicNavbar';
import Footer from '../../components/common/Footer';

/**
 * Where the Google round trip lands.
 *
 * The API redirected the browser here from /api/auth/google/callback carrying a
 * single-use code. This page spends it on the API for a token, and the session it
 * returns is the ordinary one: the same localStorage entry, the same user object
 * and the same dashboard every other sign-in ends at.
 *
 * The code cannot be spent twice, so the exchange is guarded against running
 * again (React runs effects twice in development, and a back button would ask for
 * the same code one more time).
 */
const GoogleCallback = () => {
  const { loginWithGoogle } = useAuth();
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const [error, setError] = useState(null);
  const exchanged = useRef(false);

  useEffect(() => {
    if (exchanged.current) return;
    exchanged.current = true;

    const code = params.get('code');

    if (!code) {
      navigate('/login?google=failed', { replace: true });
      return;
    }

    loginWithGoogle(code)
      .then((data) => {
        toast.success(
          data.created
            ? 'Welcome to SAKANIA! Your account is ready.'
            : 'Welcome back!'
        );
        // Same landing as the password login: whichever dashboard the role has.
        navigate(`/${data.user?.role === 'admin' ? 'admin' : data.user?.role}`, { replace: true });
      })
      .catch(() => {
        setError('This Google sign-in link has expired. Please try again.');
      });
  }, [loginWithGoogle, navigate, params]);

  const backToLogin = () => navigate('/login', { replace: true });

  return (
    <div className="min-h-screen flex flex-col bg-slate-50/70 antialiased selection:bg-orange-500 selection:text-white">
      <PublicNavbar />

      <main className="flex-1 flex items-center justify-center px-4 py-10 sm:px-6 lg:px-8">
        <motion.div
          initial={{ opacity: 0, y: 10 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.25, ease: 'easeOut' }}
          className="w-full max-w-[420px] bg-white rounded-2xl border border-slate-200/80 shadow-[0_4px_25px_-5px_rgba(0,0,0,0.06)] p-6 sm:p-8 text-center"
        >
          {error ? (
            <>
              <div className="w-12 h-12 mx-auto rounded-xl bg-rose-50 text-rose-600 border border-rose-100 flex items-center justify-center text-xl font-bold">
                !
              </div>
              <h1 className="mt-4 text-lg font-bold tracking-tight text-slate-900 font-display">
                Google sign-in failed
              </h1>
              <p className="mt-1.5 text-sm text-slate-500">{error}</p>
              <button
                type="button"
                onClick={backToLogin}
                className="mt-6 w-full h-11 bg-gradient-to-r from-orange-500 to-orange-600 hover:from-orange-600 hover:to-orange-700 text-white font-medium text-sm rounded-xl shadow-md hover:shadow-lg hover:shadow-orange-500/25 active:translate-y-0 active:scale-[0.98] transition-all duration-200 cursor-pointer"
              >
                Back to Sign In
              </button>
            </>
          ) : (
            <>
              <div className="mx-auto h-12 w-12 rounded-xl bg-orange-50 border border-orange-100 flex items-center justify-center">
                <img src="/Logo-sakania.png" alt="Logo" className="h-10 w-10 animate-pulse" />
              </div>
              <h1 className="mt-4 text-lg font-bold tracking-tight text-slate-900 font-display">
                Signing you in
              </h1>
              <p className="mt-1.5 text-sm text-slate-500">One moment while Google finishes.</p>
            </>
          )}
        </motion.div>
      </main>

      <Footer />
    </div>
  );
};

export default GoogleCallback;