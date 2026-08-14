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

  describe('hard deadline', () => {
    // The 2026-08 livelock: a resetSession firing faster than ttlMs re-armed the
    // reaper every time, so the instance polled WhatsApp for two days straight.
    it('fires even when a reset loop keeps re-arming the idle timer', async () => {
      const { reaper, calls } = makeReaper({ hardTtlMs: 5000 });
      for (let elapsed = 0; elapsed < 5000; elapsed += 500) {
        reaper.noteQr();
        await vi.advanceTimersByTimeAsync(500);
        reaper.reset(); // what resetSession does, faster than the 1000ms TTL
      }
      expect(calls.expired).toBe(0);
      reaper.noteQr();
      expect(calls.expired).toBe(1);
    });

    it('a successful pairing clears the deadline, so a later unlink gets a full window', async () => {
      const { reaper, calls } = makeReaper({ ttlMs: 100000, hardTtlMs: 5000 });
      reaper.noteQr();
      await vi.advanceTimersByTimeAsync(4900);
      reaper.noteScanned();
      await vi.advanceTimersByTimeAsync(60000); // paired and working
      reaper.reset();
      reaper.noteQr();                          // unlinked, back to the QR screen
      expect(calls.expired).toBe(0);
      await vi.advanceTimersByTimeAsync(4900);
      reaper.reset();
      reaper.noteQr();
      expect(calls.expired).toBe(0);            // 4.9s into the NEW window, not 9.8s
    });

    it('is disabled when hardTtlMs is not set', async () => {
      const { reaper, calls } = makeReaper({ ttlMs: 1000 });
      for (let i = 0; i < 20; i++) {
        reaper.noteQr();
        await vi.advanceTimersByTimeAsync(500);
        reaper.reset();
      }
      expect(calls.expired).toBe(0);
    });
  });
});
