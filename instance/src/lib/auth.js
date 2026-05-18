'use strict';

const { timingSafeEqual } = require('crypto');

function safeEqual(a, b) {
  const ab = Buffer.from(String(a));
  const bb = Buffer.from(String(b));
  if (ab.length !== bb.length) return false;
  return timingSafeEqual(ab, bb);
}

function makeAuthMiddleware(config) {
  return function authMiddleware(req, res, next) {
    const { idInstance, token } = req.params;
    if (idInstance !== String(config.idInstance)) {
      return res.status(404).json({ error: 'unknown_instance' });
    }
    if (!safeEqual(token, config.apiToken)) {
      return res.status(401).json({ error: 'unauthorized' });
    }
    next();
  };
}

module.exports = { makeAuthMiddleware, safeEqual };
