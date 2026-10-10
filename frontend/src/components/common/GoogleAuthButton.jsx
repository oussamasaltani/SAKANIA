import { useState } from 'react';
import toast from 'react-hot-toast';
import { googleAuthorizationUrl } from '../../services/authService';
import { Button } from '../ui/button';
import BrandLoader from './BrandLoader';

/** Google's own mark, as their branding guidelines ask any "Sign in with" button to carry it. */
const GoogleMark = () => (
  <svg viewBox="0 0 18 18" className="w-4 h-4 shrink-0" aria-hidden="true" focusable="false">
    <path
      fill="#4285F4"
      d="M17.64 9.205c0-.638-.057-1.252-.164-1.841H9v3.482h4.844a4.14 4.14 0 0 1-1.797 2.715v2.258h2.909c1.703-1.568 2.684-3.879 2.684-6.614z"
    />
    <path
      fill="#34A853"
      d="M9 18c2.43 0 4.468-.806 5.956-2.181l-2.909-2.258c-.806.54-1.835.859-3.047.859-2.344 0-4.328-1.585-5.037-3.714H.956v2.332A9 9 0 0 0 9 18z"
    />
    <path
      fill="#FBBC05"
      d="M3.963 10.706A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.281-1.706V4.962H.956A9 9 0 0 0 0 9c0 1.452.347 2.827.956 4.038l3.007-2.332z"
    />
    <path
      fill="#EA4335"
      d="M9 3.58c1.322 0 2.508.454 3.441 1.346l2.581-2.581C13.463.893 11.426 0 9 0A9 9 0 0 0 .956 4.962l3.007 2.332C4.672 5.165 6.656 3.58 9 3.58z"
    />
  </svg>
);

/**
 * "Continue with Google", the same on the login page and on the register page.
 *
 * All it does is ask the API where to send the browser; the page is then left
 * behind, because from there on the navigation belongs to Google. It comes back
 * through /auth/google/callback, which is where the session is finished.
 *
 * roleId carries the answer the register form has already collected, so somebody
 * new is created as the tenant or the owner they picked. An account that already
 * exists keeps the role it has.
 */
const GoogleAuthButton = ({ roleId, returnTo = 'login', className = '' }) => {
  const [loading, setLoading] = useState(false);

  const start = async () => {
    setLoading(true);

    try {
      const { data } = await googleAuthorizationUrl({ role_id: roleId, return_to: returnTo });

      // A full navigation rather than a router push: this leaves for Google.
      window.location.assign(data.url);
    } catch (err) {
      toast.error(err.response?.data?.message || 'Could not start Google sign-in');
      setLoading(false);
    }
  };

  return (
    <div className={className}>
      <div className="flex items-center gap-3 my-5">
        <span className="h-px flex-1 bg-slate-200" />
        <span className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">or</span>
        <span className="h-px flex-1 bg-slate-200" />
      </div>

      <Button
        type="button"
        variant="outline"
        onClick={start}
        disabled={loading}
        className="w-full h-11 border-slate-200 bg-white text-slate-700 font-medium text-sm rounded-xl shadow-xs hover:bg-slate-50 hover:border-slate-300 hover:shadow-md active:scale-[0.98] transition-all duration-200 cursor-pointer"
      >
        {loading ? (
          <div className="flex items-center justify-center gap-2">
            <BrandLoader size="small" showText={false} />
            <span>Opening Google...</span>
          </div>
        ) : (
          <>
            <GoogleMark />
            <span>Continue with Google</span>
          </>
        )}
      </Button>
    </div>
  );
};

export default GoogleAuthButton;