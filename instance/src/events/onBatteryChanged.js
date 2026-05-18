'use strict';

module.exports = (ctx) => async (info) => {
  try {
    const payload = ctx.mapper.toDeviceInfo(info);
    await ctx.webhookSender.enqueue('deviceInfo', payload);
  } catch (err) {
    ctx.logger.error({ err: err.message }, 'onBatteryChanged: failed');
  }
};
