'use strict';

const { QrIdleReaper } = require('../src/lib/QrIdleReaper');

const silentLogger = { info() {}, warn() {}, error() {} };

function makeReaper(overrides = {}) {
  const calls = { expired: 0 };
  const reaper = new QrIdleReaper({
    ttlMs: 1000,
    logger: silentLogger,
    onExpire: () => { calls.expired += 1; },
    ...overrides,
  });
  return { reaper, calls };
}

describe('QrIdleReaper', () => {
  beforeEach(() => { vi.useFakeTimers(); });
  afterEach(() => { vi.useRealTimers(); });

  it('stops the instance once the QR has gone unscanned for the whole TTL', async () => {
    const { reaper, calls } = makeReaper();
    reaper.noteQr();
    await vi.advanceTimersByTimeAsync(999);
    expect(calls.expired).toBe(0);
    await vi.advanceTimersByTimeAsync(1);
    expect(calls.expired).toBe(1);
  });

  // The regression that matters: whatsapp-web.js emits a new QR every ~20s, so a
  // timer restarted per event would never fire and the instance would poll forever.
  it('counts from the first QR, so the 20s refresh cannot postpone it', async () => {
    const { reaper, calls } = makeReaper();
    reaper.noteQr();
    for (let elapsed = 0; elapsed < 900; elapsed += 100) {
      await vi.advanceTimersByTimeAsync(100);
      reaper.noteQr();
    }
    expect(calls.expired).toBe(0);
    await vi.advanceTimersByTimeAsync(100);
    expect(calls.expired).toBe(1);
  });

  it('does not stop an instance somebody scanned', async () => {
    const { reaper, calls } = makeReaper();
    reaper.noteQr();
    await vi.advanceTimersByTimeAsync(500);
    reaper.noteScanned();
    await vi.advanceTimersByTimeAsync(5000);
    expect(calls.expired).toBe(0);
  });

  it('ignores QRs that arrive after a successful scan', async () => {
    const { reaper, calls } = makeReaper();
    reaper.noteScanned();
    reaper.noteQr();
    await vi.advanceTimersByTimeAsync(5000);
    expect(calls.expired).toBe(0);
  });

  // After an unlink the instance is back at the QR screen and must be reaped again.
  it('re-arms after reset, so an unlinked instance cannot idle forever', async () => {
    const { reaper, calls } = makeReaper();
    reaper.noteQr();
    reaper.noteScanned();
    reaper.reset();
    reaper.noteQr();
    await vi.advanceTimersByTimeAsync(1000);
    expect(calls.expired).toBe(1);
  });

  it('stop() prevents a pending timer from firing during shutdown', async () => {
    const { reaper, calls } = makeReaper();
    reaper.noteQr();
    reaper.stop();
    await vi.advanceTimersByTimeAsync(5000);
    expect(calls.expired).toBe(0);
  });

  it('treats a non-positive TTL as disabled', async () => {
    for (const ttlMs of [0, -1, NaN, 'nonsense']) {
      const { reaper, calls } = makeReaper({ ttlMs });
      reaper.noteQr();
      await vi.advanceTimersByTimeAsync(60000);
      expect(calls.expired).toBe(0);
    }
  });
});
