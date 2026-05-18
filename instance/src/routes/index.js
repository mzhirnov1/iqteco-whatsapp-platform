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

function mountRoutes(app, ctx) {
  const auth = makeAuthMiddleware(ctx.config);
  const prefix = '/waInstance:idInstance';

  app.get(`${prefix}/getStateInstance/:token`, auth, getStateInstance(ctx));
  app.get(`${prefix}/getSettings/:token`, auth, getSettings(ctx));
  app.post(`${prefix}/setSettings/:token`, auth, setSettings(ctx));
  app.get(`${prefix}/getQrCode/:token`, auth, getQrCode(ctx));
  app.post(`${prefix}/getAuthorizationCode/:token`, auth, getAuthorizationCode(ctx));
  app.get(`${prefix}/getAuthorizationCode/:token`, auth, getAuthorizationCode(ctx));
  app.get(`${prefix}/reboot/:token`, auth, reboot(ctx));
  app.get(`${prefix}/logout/:token`, auth, logout(ctx));
  app.post(`${prefix}/sendMessage/:token`, auth, sendMessage(ctx));

  app.get(`${prefix}/receiveNotification/:token`, auth, receiveNotification(ctx));
  app.delete(`${prefix}/deleteNotification/:token/:receiptId`, auth, deleteNotification(ctx));

  app.use(`${prefix}/:method/:token`, auth, (req, res) => {
    res.status(501).json({ error: 'not_implemented_yet', method: req.params.method });
  });
}

module.exports = { mountRoutes };
