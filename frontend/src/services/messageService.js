import api from './api';

export const getConversations = () => api.get('/messages');

export const getConversation = (conversationId) => api.get(`/messages/conversations/${conversationId}`);

export const getUnreadCount = () => api.get('/messages/unread/count');

export const sendMessage = (data) => api.post('/messages', data);