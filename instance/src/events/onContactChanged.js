'use strict';

module.exports = (ctx) => async (message, oldId, newId, isContact) => {
  try {
    const payload = ctx.mapper.toContactChanged(message, oldId, newId, isContact);
    await ctx.webhookSender.enqueue('contactChanged', payload);
  } catch (err) {
    ctx.logger.error({ err: err.message }, 'onContactChanged: failed');
  }
};
