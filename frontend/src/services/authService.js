import api from './api';

export const register = (data) => api.post('/register', data);

export const login = (data) => api.post('/login', data);

// "Continue with Google": the browser is sent to the URL the first call returns,
// then comes back through /auth/google/callback, which redirects here carrying a
// one-time code. Exchanging it returns the same { user, token } the login above
// returns, so both ways in end in the same session.
export const googleAuthorizationUrl = (data) => api.post('/auth/google/redirect', data);

export const exchangeGoogleCode = (code) => api.post('/auth/google/exchange', { code });

export const logout = () => api.post('/logout');

export const getUser = () => api.get('/user');

export const getProfile = () => api.get('/profile');

export const updateProfile = (data) => api.put('/profile', data);

export const changePassword = (data) => api.put('/profile/password', data);

export const deleteAvatar = () => api.delete('/profile/avatar');
