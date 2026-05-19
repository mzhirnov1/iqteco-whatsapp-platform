'use strict';

const { makeAuthMiddleware } = require('../lib/auth');

const getStateInstance = require('./getStateInstance');
const sendMessage = require('./sendMessage');
const getQrCode = require('./getQrCode');
const getAuthorizationCode = require('./getAuthorizationCode');
const reboot = require('./reboot');
const logout = require('./logout');
const getSettings = require('./getSettings');
const setSettings = require('./setSettings');
const receiveNotification = require('./receiveNotification');
const deleteNotification = require('./deleteNotification');

const sendFileByUrl = require('./sendFileByUrl');
const sendImageByUrl = require('./sendImageByUrl');
const sendFileByUpload = require('./sendFileByUpload');
const sendLocation = require('./sendLocation');
const sendContact = require('./sendContact');
const forwardMessages = require('./forwardMessages');
const markChatAsRead = require('./markChatAsRead');

const checkWhatsapp = require('./checkWhatsapp');
const getContacts = require('./getContacts');
const getContactInfo = require('./getContactInfo');
const getAvatar = require('./getAvatar');
const getChats = require('./getChats');
const getChatHistory = require('./getChatHistory');
const lastIncomingMessages = require('./lastIncomingMessages');
const lastOutgoingMessages = require('./lastOutgoingMessages');

const editMessage = require('./editMessage');
const deleteMessage = require('./deleteMessage');
const archiveChat = require('./archiveChat');

const media = require('./media');

function mountRoutes(app, ctx) {
  const auth = makeAuthMiddleware(ctx.config);
  const prefix = '/waInstance:idInstance';

  // Account / state
  app.get(`${prefix}/getStateInstance/:token`, auth, getStateInstance(ctx));
  app.get(`${prefix}/getSettings/:token`, auth, getSettings(ctx));
  app.post(`${prefix}/setSettings/:token`, auth, setSettings(ctx));
  app.get(`${prefix}/getQrCode/:token`, auth, getQrCode(ctx));
  app.post(`${prefix}/getAuthorizationCode/:token`, auth, getAuthorizationCode(ctx));
  app.get(`${prefix}/getAuthorizationCode/:token`, auth, getAuthorizationCode(ctx));
  app.get(`${prefix}/reboot/:token`, auth, reboot(ctx));
  app.get(`${prefix}/logout/:token`, auth, logout(ctx));

  // Sending
  app.post(`${prefix}/sendMessage/:token`, auth, sendMessage(ctx));
  app.post(`${prefix}/sendFileByUrl/:token`, auth, sendFileByUrl(ctx));
  app.post(`${prefix}/sendImageByUrl/:token`, auth, sendImageByUrl(ctx));
  app.post(`${prefix}/sendFileByUpload/:token`, auth, sendFileByUpload.middleware, sendFileByUpload.handler(ctx));
  app.post(`${prefix}/sendLocation/:token`, auth, sendLocation(ctx));
  app.post(`${prefix}/sendContact/:token`, auth, sendContact(ctx));
  app.post(`${prefix}/forwardMessages/:token`, auth, forwardMessages(ctx));
  app.post(`${prefix}/markChatAsRead/:token`, auth, markChatAsRead(ctx));

  // Edit / delete / archive (Phase 4)
  app.post(`${prefix}/editMessage/:token`, auth, editMessage(ctx));
  app.post(`${prefix}/deleteMessage/:token`, auth, deleteMessage(ctx));
  app.post(`${prefix}/archiveChat/:token`, auth, archiveChat(ctx, true));
  app.post(`${prefix}/unarchiveChat/:token`, auth, archiveChat(ctx, false));

  // Queries
  app.post(`${prefix}/checkWhatsapp/:token`, auth, checkWhatsapp(ctx));
  app.get(`${prefix}/checkWhatsapp/:token`, auth, checkWhatsapp(ctx));
  app.get(`${prefix}/getContacts/:token`, auth, getContacts(ctx));
  app.get(`${prefix}/getChats/:token`, auth, getChats(ctx));
  app.post(`${prefix}/getContactInfo/:token`, auth, getContactInfo(ctx));
  app.get(`${prefix}/getAvatar/:token`, auth, getAvatar(ctx));
  app.post(`${prefix}/getAvatar/:token`, auth, getAvatar(ctx));
  app.post(`${prefix}/getChatHistory/:token`, auth, getChatHistory(ctx));
  app.get(`${prefix}/lastIncomingMessages/:token`, auth, lastIncomingMessages(ctx));
  app.get(`${prefix}/lastOutgoingMessages/:token`, auth, lastOutgoingMessages(ctx));

  // Media file download (Green API совместимый downloadUrl)
  app.get(`${prefix}/media/:token/:messageId`, auth, media(ctx));

  // Notifications (push-only)
  app.get(`${prefix}/receiveNotification/:token`, auth, receiveNotification(ctx));
  app.delete(`${prefix}/deleteNotification/:token/:receiptId`, auth, deleteNotification(ctx));

  // Fallback
  app.use(`${prefix}/:method/:token`, auth, (req, res) => {
    res.status(501).json({ error: 'not_implemented_yet', method: req.params.method });
  });
}

module.exports = { mountRoutes };
