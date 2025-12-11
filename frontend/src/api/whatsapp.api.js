import apiClient from './axios';

/**
 * Create WhatsApp QR link for linking account
 */
export const createWhatsAppLink = async (apiSecret) => {
  const response = await apiClient.post('/whatsapp/create-link', { api_secret: apiSecret });
  return response.data;
};

/**
 * Get WhatsApp account info (for polling after QR scan)
 */
export const getWhatsAppAccountInfo = async (token) => {
  const response = await apiClient.get(`/whatsapp/account-info?token=${token}`);
  return response.data;
};

/**
 * Check for connected WhatsApp account (better polling method)
 */
export const checkWhatsAppConnection = async () => {
  const response = await apiClient.get('/whatsapp/check-connection');
  return response.data;
};

/**
 * Confirm WhatsApp link after successful QR scan
 */
export const confirmWhatsAppLink = async (token) => {
  const response = await apiClient.post('/whatsapp/confirm-link', { token });
  return response.data;
};

/**
 * Relink existing WhatsApp account
 */
export const relinkWhatsAppAccount = async () => {
  const response = await apiClient.post('/whatsapp/relink');
  return response.data;
};

/**
 * Disconnect WhatsApp account
 */
export const disconnectWhatsApp = async () => {
  const response = await apiClient.post('/whatsapp/disconnect');
  return response.data;
};

/**
 * Get WhatsApp settings
 */
export const getWhatsAppSettings = async () => {
  const response = await apiClient.get('/whatsapp/settings');
  return response.data;
};

/**
 * Update WhatsApp settings
 */
export const updateWhatsAppSettings = async (data) => {
  const response = await apiClient.put('/whatsapp/settings', data);
  return response.data;
};

/**
 * Send test WhatsApp message
 */
export const testSendWhatsApp = async (message = null) => {
  const response = await apiClient.post('/whatsapp/test-send', message ? { message } : {});
  return response.data;
};

/**
 * Preview today's attendance recap
 */
export const previewWhatsAppRecap = async (date = null) => {
  const url = date ? `/whatsapp/preview-recap?date=${date}` : '/whatsapp/preview-recap';
  const response = await apiClient.get(url);
  return response.data;
};

/**
 * Manually send attendance recap
 */
export const sendWhatsAppRecap = async (date = null) => {
  const url = date ? `/whatsapp/send-recap?date=${date}` : '/whatsapp/send-recap';
  const response = await apiClient.post(url);
  return response.data;
};
