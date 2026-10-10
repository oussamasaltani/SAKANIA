import api from './api';

export const getDashboard = () => api.get('/owner/dashboard');

export const getProperties = (params) => api.get('/owner/properties', { params });

export const getProperty = (id) => api.get(`/owner/properties/${id}`);

export const createProperty = (data) => api.post('/owner/properties', data);

export const updateProperty = (id, data) => api.put(`/owner/properties/${id}`, data);

export const deleteProperty = (id) => api.delete(`/owner/properties/${id}`);

export const getReservations = () => api.get('/owner/reservations');

export const getReservation = (id) => api.get(`/owner/reservations/${id}`);

export const acceptReservation = (id) => api.put(`/owner/reservations/${id}/accept`);

export const rejectReservation = (id) => api.put(`/owner/reservations/${id}/reject`);

export const getContracts = () => api.get('/owner/contracts');

export const getContract = (id) => api.get(`/owner/contracts/${id}`);

export const getPayments = () => api.get('/owner/payments');
