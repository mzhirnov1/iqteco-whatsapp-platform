'use strict';

module.exports = (ctx) => async (_req, res) => {
  const cfg = ctx.config;
  const settings = ctx.adminConfig.settings || {};
  res.json({
    wid: ctx.state.wid,
    countryInstance: settings.countryInstance ?? '',
    typeAccount: 'whatsapp',
    webhookUrl: ctx.adminConfig.webhookUrl ?? '',
    webhookUrlToken: '',
    delaySendMessagesMilliseconds: settings.delaySendMessagesMilliseconds ?? 1000,
    markIncomingMessagesReaded: settings.markIncomingMessagesReaded ?? 'no',
    markIncomingMessagesReadedOnReply: settings.markIncomingMessagesReadedOnReply ?? 'no',
    sharedSession: settings.sharedSession ?? 'no',
    proxyInstance: settings.proxyInstance ?? '',
    outgoingWebhook: settings.outgoingWebhook ?? 'yes',
    outgoingMessageWebhook: settings.outgoingMessageWebhook ?? 'yes',
    outgoingAPIMessageWebhook: settings.outgoingAPIMessageWebhook ?? 'yes',
    incomingWebhook: settings.incomingWebhook ?? 'yes',
    deviceWebhook: settings.deviceWebhook ?? 'no',
    stateWebhook: settings.stateWebhook ?? 'yes',
    keepOnlineStatus: settings.keepOnlineStatus ?? 'no',
    pollMessageWebhook: settings.pollMessageWebhook ?? 'yes',
    incomingBlockWebhook: settings.incomingBlockWebhook ?? 'no',
    incomingCallWebhook: settings.incomingCallWebhook ?? 'no',
    editedMessageWebhook: settings.editedMessageWebhook ?? 'no',
    deletedMessageWebhook: settings.deletedMessageWebhook ?? 'no',
  });
};
