'use strict';

const { Binary } = require('mongodb');

/**
 * PageForensics — a flight recorder for the WhatsApp Web page.
 *
 * Fresh pairings die with a bare `disconnected: LOGOUT` minutes after the scan
 * (2026-08-30…09-04: 13 of 20 first links). The library reports only the fact;
 * the reason lives in the page — its console, the socket/stream state machine,
 * the arguments WhatsApp passes to `Cmd.logout`, and what the UI showed right
 * before it happened. This class keeps all of that in a ring buffer and, while
 * the pairing window is open, a screenshot every few seconds; `dump()` writes
 * the lot to the log and to the `forensics` collection the moment the client
 * disconnects.
 *
 * In-page hooks are installed by `pageHooks` (see below), which the client
 * passes to `page.evaluateOnNewDocument` via whatsapp-web.js's `evalOnNewDoc`
 * option, so they survive the SPA navigations WhatsApp Web performs after login.
 */

const DEFAULTS = {
  ringSize: 400,
  windowMs: 15 * 60 * 1000,
  shotIntervalMs: 5000,
  shotsToKeep: 2,
  opTimeoutMs: 5000,
  ttlDays: 7,
  maxText: 600,
};

function withTimeout(promise, ms, label) {
  let timer;
  const gate = new Promise((_, reject) => {
    timer = setTimeout(() => reject(new Error(`${label} timed out after ${ms}ms`)), ms);
  });
  return Promise.race([promise, gate]).finally(() => clearTimeout(timer));
}

/**
 * Runs inside the page on every document start. Polls until WhatsApp Web's
 * module system and our exposed reporter exist, then subscribes to the state
 * machines whose transitions precede a logout. Must stay self-contained: it is
 * serialized and shipped into the browser.
 */
function pageHooks() {
  const send = (kind, text) => {
    try { window.__waForensics(kind, String(text).slice(0, 600)); } catch { /* not exposed yet */ }
  };
  const safe = (v) => { try { return JSON.stringify(v); } catch { return String(v); } };
  const startedAt = Date.now();
  const timer = setInterval(() => {
    if (Date.now() - startedAt > 180000) { clearInterval(timer); return; }
    if (typeof window.require !== 'function' || typeof window.__waForensics !== 'function') return;
    let Socket, Stream, Cmd;
    try {
      Socket = window.require('WAWebSocketModel').Socket;
      Stream = window.require('WAWebStreamModel').Stream;
      Cmd = window.require('WAWebCmd').Cmd;
    } catch { return; } // modules not bootstrapped yet
    if (!Socket || !Stream || !Cmd) return;
    clearInterval(timer);
    try {
      Socket.on('change:state', (_m, s) => send('socket.state', s));
      Socket.on('change:stream', (_m, s) => send('socket.stream', s));
      Socket.on('change:hasSynced', (_m, s) => send('socket.hasSynced', s));
      Stream.on('change:mode', (_m, s) => send('stream.mode', s));
      Stream.on('change:info', (_m, s) => send('stream.info', s));
      Cmd.on('logout', (...a) => send('cmd.logout', safe(a)));
      Cmd.on('logout_from_bridge', (...a) => send('cmd.logout_from_bridge', safe(a)));
      send('hooks', `installed socket=${Socket.state} stream=${Stream.mode} info=${Stream.info}`);
    } catch (e) {
      send('hooks', 'install failed: ' + (e && e.message));
    }
  }, 250);
}

class PageForensics {
  constructor({ idInstance, db, logger, now = Date.now, ...opts }) {
    this.idInstance = String(idInstance);
    this.db = db || null;
    this.logger = logger || console;
    this.now = now;
    this.opts = { ...DEFAULTS, ...opts };
    this.events = [];
    this.shots = [];
    this._page = null;
    this._shotTimer = null;
    this._windowTimer = null;
    this._pairedAt = null;
  }

  static get pageHooks() { return pageHooks; }

  async ensureIndexes() {
    if (!this.db) return;
    const col = this.db.collection('forensics');
    await col.createIndex({ at: 1 }, { expireAfterSeconds: this.opts.ttlDays * 86400 });
    await col.createIndex({ idInstance: 1, at: -1 });
  }

  note(kind, text) {
    this.events.push({ t: this.now(), kind, text: String(text ?? '').slice(0, this.opts.maxText) });
    if (this.events.length > this.opts.ringSize) {
      this.events.splice(0, this.events.length - this.opts.ringSize);
    }
  }

  /** Wire page-level listeners. Safe to call again with the same page. */
  attach(page) {
    if (!page || page === this._page) return;
    this.stopShots();
    this._page = page;
    page.on('console', (msg) => {
      let type = 'log';
      try { type = msg.type(); } catch { /* keep default */ }
      this.note('console.' + type, msg.text());
    });
    page.on('pageerror', (err) => this.note('pageerror', err?.message || err));
    page.on('requestfailed', (req) => {
      let text = '';
      try { text = `${req.failure()?.errorText || '?'} ${req.method()} ${req.url()}`; } catch { text = 'requestfailed'; }
      this.note('requestfailed', text);
    });
    page.on('framenavigated', (frame) => {
      try { if (frame.parentFrame() === null) this.note('navigated', frame.url()); } catch { /* ignore */ }
    });
    page.on('close', () => this.note('page', 'closed'));
    if (typeof page.exposeFunction === 'function') {
      page.exposeFunction('__waForensics', (kind, text) => this.note('page.' + kind, text))
        .catch((err) => {
          // Already exposed on this page (re-attach after a navigation) is fine.
          if (!/already exists|has been already registered/i.test(String(err?.message))) {
            this.logger.warn({ err: err?.message }, 'forensics: exposeFunction failed');
          }
        });
    }
    this.note('forensics', 'attached');
  }

  /** The pairing window opened (authenticated/ready): start the screenshot loop. */
  markPaired(source) {
    this.note('paired', source);
    if (this._shotTimer) return; // window already running — keep its clock
    this._pairedAt = this.now();
    this._shotTimer = setInterval(() => { this._snap().catch(() => {}); }, this.opts.shotIntervalMs);
    this._shotTimer.unref?.();
    this._windowTimer = setTimeout(() => this.stopShots(), this.opts.windowMs);
    this._windowTimer.unref?.();
  }

  stopShots() {
    if (this._shotTimer) clearInterval(this._shotTimer);
    if (this._windowTimer) clearTimeout(this._windowTimer);
    this._shotTimer = null;
    this._windowTimer = null;
  }

  /** Forget the page (the client is being destroyed). Events are kept. */
  detach() {
    this.stopShots();
    this._page = null;
  }

  async _screenshot() {
    const page = this._page;
    if (!page || typeof page.screenshot !== 'function') return null;
    return withTimeout(page.screenshot({ type: 'jpeg', quality: 55 }), this.opts.opTimeoutMs, 'screenshot');
  }

  async _snap() {
    const buf = await this._screenshot().catch(() => null);
    if (!buf) return;
    this.shots.push({ t: this.now(), jpeg: buf });
    if (this.shots.length > this.opts.shotsToKeep) this.shots.splice(0, this.shots.length - this.opts.shotsToKeep);
  }

  async _pageState() {
    const page = this._page;
    if (!page || typeof page.evaluate !== 'function') return { err: 'no page' };
    return withTimeout(page.evaluate(() => {
      const out = { url: location.href, title: document.title };
      try {
        const Socket = window.require('WAWebSocketModel').Socket;
        const Stream = window.require('WAWebStreamModel').Stream;
        out.socket = Socket.state;
        out.stream = Stream.mode;
        out.streamInfo = Stream.info;
        out.hasSynced = Socket.hasSynced;
      } catch (e) { out.err = String(e && e.message || e); }
      try {
        const text = (document.body && document.body.innerText) || '';
        out.bodyText = text.replace(/\s+/g, ' ').slice(0, 400);
      } catch { /* ignore */ }
      return out;
    }), this.opts.opTimeoutMs, 'pageState').catch((err) => ({ err: err.message }));
  }

  /**
   * Freeze the recorder's contents: page state, last screenshots plus one taken
   * now, and the event ring. Logged in full (minus images) and stored in Mongo.
   */
  async dump(reason) {
    const at = new Date(this.now());
    const pageState = await this._pageState();
    const shotNow = await this._screenshot().catch(() => null);
    const shots = [...this.shots.map((s) => ({ t: s.t, jpeg: s.jpeg, when: 'before' }))];
    if (shotNow) shots.push({ t: this.now(), jpeg: shotNow, when: 'at_dump' });
    const events = this.events.slice();
    const tail = events.slice(-120).map((e) => `${new Date(e.t).toISOString().slice(11, 23)} ${e.kind}: ${e.text}`);

    this.logger.warn({
      reason,
      pairedAt: this._pairedAt ? new Date(this._pairedAt).toISOString() : null,
      page: pageState,
      shots: shots.map((s) => ({ t: new Date(s.t).toISOString(), when: s.when, bytes: s.jpeg.length })),
      events: tail,
    }, 'forensics: dump');

    let stored = null;
    if (this.db) {
      try {
        const res = await this.db.collection('forensics').insertOne({
          idInstance: this.idInstance,
          at,
          reason: String(reason),
          pairedAt: this._pairedAt ? new Date(this._pairedAt) : null,
          page: pageState,
          events: events.map((e) => ({ t: new Date(e.t), kind: e.kind, text: e.text })),
          shots: shots.map((s) => ({ t: new Date(s.t), when: s.when, jpeg: new Binary(s.jpeg) })),
        });
        stored = res.insertedId;
        this.logger.warn({ reason, id: String(stored) }, 'forensics: stored');
      } catch (err) {
        this.logger.warn({ err: err.message }, 'forensics: store failed');
      }
    }
    return { at, reason, page: pageState, events, shots: shots.length, stored };
  }
}

module.exports = { PageForensics, pageHooks, withTimeout };
