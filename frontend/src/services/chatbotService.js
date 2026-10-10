import api from './api';

/**
 * Sends a message to the SAKANIA assistant.
 *
 * `propertyId` is the property the user is currently looking at, when there is
 * one: the backend only uses it as extra context and re-checks that the account
 * may see that property before it is attached.
 */
export const sendMessage = (message, conversationId, propertyId = null) =>
  api.post('/chatbot/send', {
    message,
    conversation_id: conversationId,
    property_id: propertyId,
  });
