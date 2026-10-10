import api from './api';

export const getDashboard = () => api.get('/tenant/dashboard');

export const getReservations = () => api.get('/tenant/reservations');

export const getContracts = () => api.get('/tenant/contracts');

export const getContract = (id) => api.get(`/tenant/contracts/${id}`);

export const getPayments = () => api.get('/tenant/payments');

export const cancelReservation = (id) => api.put(`/reservations/${id}/cancel`);

export const createReservation = (data) => api.post('/reservations', data);
