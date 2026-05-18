'use strict';

const WA_TO_GREEN = Object.freeze({
  CONNECTED: 'authorized',
  OPENING: 'starting',
  PAIRING: 'starting',
  UNPAIRED: 'notAuthorized',
  UNPAIRED_IDLE: 'notAuthorized',
  UNLAUNCHED: 'notAuthorized',
  CONFLICT: 'sleepMode',
  DEPRECATED_VERSION: 'starting',
  TIMEOUT: 'starting',
  PROXYBLOCK: 'starting',
  TOS_BLOCK: 'blocked',
  SMB_TOS_BLOCK: 'blocked',
});

const ACK_TO_GREEN = Object.freeze({
  '-1': 'failed',
  '0': 'pending',
  '1': 'sent',
  '2': 'delivered',
  '3': 'read',
  '4': 'played',
});

function mapWAStateToGreen(state) {
  if (state == null) return 'notAuthorized';
  return WA_TO_GREEN[state] || 'starting';
}

function mapAckToGreen(ack) {
  if (ack == null) return 'pending';
  return ACK_TO_GREEN[String(ack)] || 'pending';
}

module.exports = { mapWAStateToGreen, mapAckToGreen, WA_TO_GREEN, ACK_TO_GREEN };
