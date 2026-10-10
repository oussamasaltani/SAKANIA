import { useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';
import toast from 'react-hot-toast';

/**
 * Why a Google sign-in did not finish, keyed by the flag the API puts on the
 * redirect back to the login or register page.
 *
 * The server only ever sends one of these short reasons, never a message of its
 * own, so this table is the whole of what can be said about what went wrong.
 */
const googleSignInErrors = {
  denied: 'Google sign-in was cancelled.',
  invalid_state: 'That Google sign-in attempt expired. Please try again.',
  unverified_email: 'Your Google email address has to be verified to sign in.',
  email_taken: 'This email address is already linked to another Google account.',
  inactive: 'Your account has been deactivated.',
  not_configured: 'Signing in with Google is not available on this server.',
  failed: 'Google sign-in failed. Please try again.',
};

/**
 * Tells the user why the Google sign-in did not reach a session.
 *
 * A sign-in that works never comes back here: the API redirects the browser
 * straight to /auth/google/callback. This is the other case, the browser being
 * sent back to the page it started from with ?google=<reason> on it.
 */
const useGoogleSignInNotice = () => {
  const [params, setParams] = useSearchParams();

  useEffect(() => {
    const reason = params.get('google');

    if (!reason) return;

    toast.error(googleSignInErrors[reason] || googleSignInErrors.failed);

    // Dropped from the address bar straight away, so a reload does not repeat it.
    params.delete('google');
    setParams(params, { replace: true });
  }, [params, setParams]);
};

export default useGoogleSignInNotice;