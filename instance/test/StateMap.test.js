'use strict';

const { mapWAStateToGreen, mapAckToGreen } = require('../src/lib/StateMap');

describe('mapWAStateToGreen', () => {
  it.each([
    ['CONNECTED', 'authorized'],
    ['OPENING', 'starting'],
    ['PAIRING', 'starting'],
    ['UNPAIRED', 'notAuthorized'],
    ['UNPAIRED_IDLE', 'notAuthorized'],
    ['UNLAUNCHED', 'notAuthorized'],
    ['CONFLICT', 'sleepMode'],
    ['DEPRECATED_VERSION', 'starting'],
    ['TIMEOUT', 'starting'],
    ['PROXYBLOCK', 'starting'],
    ['TOS_BLOCK', 'blocked'],
    ['SMB_TOS_BLOCK', 'blocked'],
  ])('maps %s → %s', (input, expected) => {
    expect(mapWAStateToGreen(input)).toBe(expected);
  });

  it('returns notAuthorized for null/undefined', () => {
    expect(mapWAStateToGreen(null)).toBe('notAuthorized');
    expect(mapWAStateToGreen(undefined)).toBe('notAuthorized');
  });

  it('returns starting for unknown states', () => {
    expect(mapWAStateToGreen('SOME_NEW_STATE')).toBe('starting');
  });
});

describe('mapAckToGreen', () => {
  it.each([
    [-1, 'failed'],
    [0, 'pending'],
    [1, 'sent'],
    [2, 'delivered'],
    [3, 'read'],
    [4, 'played'],
    ['-1', 'failed'],
    ['3', 'read'],
  ])('maps ack=%s → %s', (input, expected) => {
    expect(mapAckToGreen(input)).toBe(expected);
  });

  it('returns pending for null/undefined', () => {
    expect(mapAckToGreen(null)).toBe('pending');
    expect(mapAckToGreen(undefined)).toBe('pending');
  });
});
