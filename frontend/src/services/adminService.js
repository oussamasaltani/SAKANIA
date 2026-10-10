import api from './api';

export const getDashboard = () => api.get('/admin/dashboard');

export const getUsers = () => api.get('/admin/users');

export const getUser = (id) => api.get(`/admin/users/${id}`);

export const activateUser = (id) => api.put(`/admin/users/${id}/activate`);

export const deactivateUser = (id) => api.put(`/admin/users/${id}/deactivate`);

export const deleteUser = (id) => api.delete(`/admin/users/${id}`);

export const getProperties = (params) => api.get('/admin/properties', { params });

export const getProperty = (id) => api.get(`/admin/properties/${id}`);

export const approveProperty = (id) => api.put(`/admin/properties/${id}/approve`);

export const rejectProperty = (id) => api.put(`/admin/properties/${id}/reject`);

export const deleteProperty = (id) => api.delete(`/admin/properties/${id}`);

export const getReservations = (params) => api.get('/admin/reservations', { params });

export const getReservation = (id) => api.get(`/admin/reservations/${id}`);

export const deleteReservation = (id) => api.delete(`/admin/reservations/${id}`);

export const getContracts = (params) => api.get('/admin/contracts', { params });

export const getContract = (id) => api.get(`/admin/contracts/${id}`);

export const updateContractStatus = (id, status) => api.put(`/admin/contracts/${id}/status`, { status });

export const deleteContract = (id) => api.delete(`/admin/contracts/${id}`);

export const getPayments = (params) => api.get('/admin/payments', { params });

export const getPayment = (id) => api.get(`/admin/payments/${id}`);

export const updatePaymentStatus = (id, status) => api.put(`/admin/payments/${id}/status`, { status });

export const deletePayment = (id) => api.delete(`/admin/payments/${id}`);
