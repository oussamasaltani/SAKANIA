import { createContext, useContext, useState, useEffect, useCallback } from 'react';
import { getUser, login as loginService, register as registerService, logout as logoutService, exchangeGoogleCode } from '../services/authService';

const AuthContext = createContext(null);

export const useAuth = () => {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
};

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(null);
  const [token, setToken] = useState(localStorage.getItem('token'));
  const [loading, setLoading] = useState(true);

  // Re-reads the account from /api/user and publishes it.
  //
  // The effect below only re-runs when the token changes, so nothing would
  // refresh the user after a profile save. Components that show the name or the
  // avatar would keep rendering the values from login time. This is the way out:
  // it is called explicitly after a profile update.
  const refreshUser = useCallback(async () => {
    try {
      const response = await getUser();
      setUser(response.data);
      return response.data;
    } catch {
      return null;
    }
  }, []);

  useEffect(() => {
    if (token) {
      getUser()
        .then((response) => setUser(response.data))
        .catch(() => {
          localStorage.removeItem('token');
          setToken(null);
        })
        .finally(() => setLoading(false));
    } else {
      setLoading(false);
    }
  }, [token]);

  // Every way in ends here: the API always answers with { user, token }, whether
  // the account signed in with a password or came back from Google, so there is
  // one place that decides what a signed-in session looks like.
  const startSession = useCallback((data) => {
    localStorage.setItem('token', data.token);
    setToken(data.token);
    setUser(data.user);
    return data;
  }, []);

  const login = async (email, password) => {
    const response = await loginService({ email, password });
    return startSession(response.data);
  };

  const register = async (data) => {
    const response = await registerService(data);
    return startSession(response.data);
  };

  // Finishes the Google round trip: /auth/google/callback sent the browser here
  // with a one-time code, and the API turns it into the same session the email
  // and password login produces. created says whether the account was just made.
  const loginWithGoogle = async (code) => {
    const response = await exchangeGoogleCode(code);
    return startSession(response.data);
  };

  const logout = async () => {
    try {
      await logoutService();
    } finally {
      localStorage.removeItem('token');
      setToken(null);
      setUser(null);
    }
  };

  const isAdmin = user?.role === 'admin';
  const isOwner = user?.role === 'owner';
  const isTenant = user?.role === 'tenant';

  return (
    <AuthContext.Provider
      value={{
        user,
        token,
        login,
        register,
        loginWithGoogle,
        logout,
        loading,
        isAdmin,
        isOwner,
        isTenant,
        refreshUser,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
};

export default AuthContext;