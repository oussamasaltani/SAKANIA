import api from './api';

export const getCheckout = (reservationId) => api.get(`/payments/checkout/${reservationId}`);

export const processPayment = (data) => api.post('/payments/process', data);

export const getPaymentResult = (paymentId) => api.get(`/payments/${paymentId}/success`);

const asNumber = (value) => {
  const parsed = parseFloat(value);
  return Number.isFinite(parsed) ? parsed : 0;
};

/**
 * The payment endpoints wrap the list differently: the tenant route answers with
 * `{ payments: <paginator>, total_paid, total_remaining }` while the owner route
 * answers with a bare paginator. Both are unwrapped here so the pages always
 * receive a real array, whatever the payload looks like.
 */
export const normalizePayments = (payload) => {
  const body = payload?.data ?? payload ?? {};
  const paginator = Array.isArray(body) ? body : body.payments ?? body.data ?? [];
  const rows = Array.isArray(paginator) ? paginator : Array.isArray(paginator?.data) ? paginator.data : [];

  const totalPaid = body.total_paid ?? body.totalPaid;
  const totalRemaining = body.total_remaining ?? body.totalRemaining;

  const totals = {
    totalPaid: totalPaid !== undefined ? asNumber(totalPaid) : null,
    totalRemaining: totalRemaining !== undefined ? asNumber(totalRemaining) : null,
  };

  return { rows, ...totals };
};

const saveBlob = (blob, filename) => {
  const url = window.URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.URL.revokeObjectURL(url);
};

export const downloadContract = async (contractId) => {
  const response = await api.get(`/contracts/${contractId}/download`, { responseType: 'blob' });
  const disposition = response.headers['content-disposition'] || '';
  const match = disposition.match(/filename="?([^";]+)"?/i);
  saveBlob(response.data, match ? match[1] : `contract-${contractId}.pdf`);
  return response.data;
};

export const downloadReceipt = async (paymentId) => {
  const response = await api.get(`/payments/${paymentId}/receipt`, { responseType: 'blob' });
  const disposition = response.headers['content-disposition'] || '';
  const match = disposition.match(/filename="?([^";]+)"?/i);
  saveBlob(response.data, match ? match[1] : `receipt-${paymentId}.pdf`);
  return response.data;
};
