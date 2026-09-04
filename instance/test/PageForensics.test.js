'use strict';

const { PageForensics, pageHooks } = require('../src/lib/PageForensics');
const { userAgentFor, detectChromeMajor } = require('../src/client');

const silentLogger = { debug() {}, info() {}, warn() {}, error() {} };

function fakePage({ screenshot = Buffer.from('jpeg'), state = { socket: 'CONNECTED' } } = {}) {
  const handlers = {};
  return {
    handlers,
    on(event, fn) { (handlers[event] = handlers[event] || []).push(fn); },
    emit(event, ...args) { for (const fn of handlers[event] || []) fn(...args); },
    exposeFunction: vi.fn(async () => {}),
    screenshot: vi.fn(async () => screenshot),
    evaluate: vi.fn(async () => state),
  };
}

function fakeDb() {
  const inserted = [];
  return {
    inserted,
    collection: () => ({
      createIndex: async () => {},
      insertOne: async (doc) => { inserted.push(doc); return { insertedId: 'id-' + inserted.length }; },
    }),
  };
}

describe('PageForensics', () => {
  beforeEach(() => { vi.useFakeTimers(); });
  afterEach(() => { vi.useRealTimers(); });

  it('records console, page errors, failed requests and navigations from the page', () => {
    const f = new PageForensics({ idInstance: '1101008590', logger: silentLogger });
    const page = fakePage();
    f.attach(page);

    page.emit('console', { type: () => 'error', text: () => 'boom' });
    page.emit('pageerror', new Error('TypeError: x'));
    page.emit('requestfailed', { failure: () => ({ errorText: 'net::ERR_FAILED' }), method: () => 'GET', url: () => 'https://web.whatsapp.com/x' });
    page.emit('framenavigated', { parentFrame: () => null, url: () => 'https://web.whatsapp.com/?post_logout=1' });
    page.emit('framenavigated', { parentFrame: () => ({}), url: () => 'https://child' }); // sub-frame: ignored

    const kinds = f.events.map((e) => e.kind);
    expect(kinds).toEqual(['forensics', 'console.error', 'pageerror', 'requestfailed', 'navigated']);
    expect(f.events[4].text).toContain('post_logout=1');
    expect(page.exposeFunction).toHaveBeenCalledWith('__waForensics', expect.any(Function));
  });

  it('keeps the ring bounded and trims long texts', () => {
    const f = new PageForensics({ idInstance: '1', logger: silentLogger, ringSize: 5, maxText: 10 });
    for (let i = 0; i < 20; i++) f.note('console.log', 'x'.repeat(50) + i);
    expect(f.events).toHaveLength(5);
    expect(f.events[0].text).toHaveLength(10);
    expect(f.events.at(-1).text).toBe('xxxxxxxxxx');
  });

  // The pairing window: screenshots every interval, only the last few kept, and
  // the loop stops on its own after windowMs so an authorized instance does not
  // keep rendering JPEGs for the rest of its life.
  it('takes periodic screenshots after pairing, keeps the last two, stops after the window', async () => {
    const f = new PageForensics({ idInstance: '1', logger: silentLogger, shotIntervalMs: 1000, windowMs: 5000, shotsToKeep: 2 });
    const page = fakePage();
    f.attach(page);
    f.markPaired('authenticated');
    f.markPaired('ready'); // second call must not restart the clock

    await vi.advanceTimersByTimeAsync(3000);
    expect(page.screenshot).toHaveBeenCalledTimes(3);
    expect(f.shots).toHaveLength(2);

    await vi.advanceTimersByTimeAsync(10000);
    const afterWindow = page.screenshot.mock.calls.length;
    expect(afterWindow).toBeLessThanOrEqual(5);
    await vi.advanceTimersByTimeAsync(5000);
    expect(page.screenshot).toHaveBeenCalledTimes(afterWindow);
  });

  it('dumps page state, screenshots and the event ring to the log and to Mongo', async () => {
    const db = fakeDb();
    const warn = vi.fn();
    const f = new PageForensics({ idInstance: '1101008590', db, logger: { ...silentLogger, warn }, shotIntervalMs: 1000, windowMs: 60000 });
    const page = fakePage({ state: { socket: 'UNPAIRED', stream: 'DISCONNECTED', url: 'https://web.whatsapp.com/' } });
    f.attach(page);
    f.markPaired('ready');
    await vi.advanceTimersByTimeAsync(1000);
    f.note('page.cmd.logout', '["reason"]');

    const result = await f.dump('disconnected:LOGOUT');

    expect(result.page).toEqual(expect.objectContaining({ socket: 'UNPAIRED' }));
    expect(result.shots).toBe(2); // one from the loop, one taken at dump time
    expect(db.inserted).toHaveLength(1);
    const doc = db.inserted[0];
    expect(doc.idInstance).toBe('1101008590');
    expect(doc.reason).toBe('disconnected:LOGOUT');
    expect(doc.events.some((e) => e.kind === 'page.cmd.logout')).toBe(true);
    expect(doc.shots.map((s) => s.when)).toEqual(['before', 'at_dump']);
    expect(warn).toHaveBeenCalledWith(expect.objectContaining({ reason: 'disconnected:LOGOUT' }), 'forensics: dump');
  });

  it('survives a page whose evaluate and screenshot hang', async () => {
    const f = new PageForensics({ idInstance: '1', logger: silentLogger, opTimeoutMs: 100 });
    const page = fakePage();
    page.evaluate = () => new Promise(() => {});
    page.screenshot = () => new Promise(() => {});
    f.attach(page);

    const pending = f.dump('disconnected:LOGOUT');
    await vi.advanceTimersByTimeAsync(500);
    const result = await pending;

    expect(result.page.err).toMatch(/timed out/);
    expect(result.shots).toBe(0);
  });

  it('ships the in-page hooks as a self-contained function', () => {
    const src = pageHooks.toString();
    expect(src).toContain('WAWebSocketModel');
    expect(src).toContain('WAWebCmd');
    expect(src).toContain('__waForensics');
    expect(src).not.toMatch(/require\(['"]\.\//); // no node requires inside the browser code
  });
});

describe('user agent from the Chromium binary', () => {
  it('formats a Chrome UA for the given major', () => {
    expect(userAgentFor(139)).toBe('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36');
  });

  it('returns null when the binary is missing instead of throwing', () => {
    expect(detectChromeMajor('/nonexistent/chromium')).toBeNull();
    expect(detectChromeMajor('')).toBeNull();
  });
});
