'use strict';

const fs = require('fs');
const fsp = require('fs/promises');
const os = require('os');
const path = require('path');
const { copyTreeTolerant, ResilientRemoteAuth } = require('../src/client');

const silentLogger = { debug() {}, info() {}, warn() {}, error() {} };

describe('copyTreeTolerant', () => {
  let tmp;

  beforeEach(() => { tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'wa-copy-')); });
  afterEach(() => { fs.rmSync(tmp, { recursive: true, force: true }); });

  it('copies a tree file for file', async () => {
    const src = path.join(tmp, 'src');
    fs.mkdirSync(path.join(src, 'IndexedDB', 'leveldb'), { recursive: true });
    fs.writeFileSync(path.join(src, 'IndexedDB', 'leveldb', 'CURRENT'), 'MANIFEST-000001\n');
    fs.writeFileSync(path.join(src, 'IndexedDB', 'leveldb', '000029.ldb'), 'payload');

    await copyTreeTolerant(src, path.join(tmp, 'dest'));

    expect(fs.readFileSync(path.join(tmp, 'dest', 'IndexedDB', 'leveldb', 'CURRENT'), 'utf8'))
      .toBe('MANIFEST-000001\n');
    expect(fs.readFileSync(path.join(tmp, 'dest', 'IndexedDB', 'leveldb', '000029.ldb'), 'utf8'))
      .toBe('payload');
  });

  // The regression: leveldb compacts while the backup walks the profile, so an
  // entry listed by readdir is gone by the time we copy it. fs.cp() aborts the
  // whole backup with ENOENT; we skip the raced entry and keep the rest.
  it('skips entries that vanish mid-walk instead of aborting', async () => {
    const src = path.join(tmp, 'src');
    fs.mkdirSync(src, { recursive: true });
    fs.writeFileSync(path.join(src, 'keep.ldb'), 'kept');
    fs.writeFileSync(path.join(src, 'compacted.ldb'), 'doomed');

    const realCopyFile = fsp.copyFile.bind(fsp);
    const spy = vi.spyOn(fsp, 'copyFile').mockImplementation(async (from, to) => {
      if (String(from).endsWith('compacted.ldb')) {
        const err = new Error('ENOENT: no such file or directory');
        err.code = 'ENOENT';
        throw err;
      }
      return realCopyFile(from, to);
    });

    await expect(copyTreeTolerant(src, path.join(tmp, 'dest'))).resolves.toBeUndefined();
    spy.mockRestore();

    expect(fs.readFileSync(path.join(tmp, 'dest', 'keep.ldb'), 'utf8')).toBe('kept');
    expect(fs.existsSync(path.join(tmp, 'dest', 'compacted.ldb'))).toBe(false);
  });

  it('treats a missing source directory as nothing to copy', async () => {
    await expect(copyTreeTolerant(path.join(tmp, 'gone'), path.join(tmp, 'dest')))
      .resolves.toBeUndefined();
  });

  it('propagates errors that are not a compaction race', async () => {
    const src = path.join(tmp, 'src');
    fs.mkdirSync(src, { recursive: true });
    fs.writeFileSync(path.join(src, 'a.ldb'), 'x');

    const spy = vi.spyOn(fsp, 'copyFile').mockImplementation(async () => {
      const err = new Error('EACCES: permission denied');
      err.code = 'EACCES';
      throw err;
    });

    await expect(copyTreeTolerant(src, path.join(tmp, 'dest'))).rejects.toThrow(/EACCES/);
    spy.mockRestore();
  });
});

describe('ResilientRemoteAuth', () => {
  function makeAuth(canBackup) {
    return new ResilientRemoteAuth({
      store: { sessionExists: async () => false, save: async () => {}, extract: async () => {}, delete: async () => {} },
      clientId: '1101008417',
      backupSyncIntervalMs: 60000,
      canBackup,
      logger: silentLogger,
    });
  }

  // The fuel of the 2026-08 QR loop: a leaked backupSync kept storing the empty
  // profile of an unpaired client, and the stored blob made onQR's watchdog reset
  // the session every few minutes forever.
  it('does not back up a client that is not paired', async () => {
    const auth = makeAuth(() => false);
    const compress = vi.spyOn(auth, 'compressSession');

    await auth.storeRemoteSession({ emit: false });

    expect(compress).not.toHaveBeenCalled();
  });

  it('backs up a paired client', async () => {
    const auth = makeAuth(() => true);
    auth.sessionName = 'RemoteAuth-1101008417';
    vi.spyOn(auth, 'isValidPath').mockResolvedValue(true);
    vi.spyOn(auth, 'compressSession').mockResolvedValue('/tmp/RemoteAuth-1101008417.zip');
    const save = vi.spyOn(auth.store, 'save');

    await auth.storeRemoteSession({ emit: false });

    expect(save).toHaveBeenCalledWith({ session: 'RemoteAuth-1101008417' });
  });

  it('logs a failed backup instead of raising unhandledRejection', async () => {
    const auth = makeAuth(() => true);
    auth.sessionName = 'RemoteAuth-1101008417';
    vi.spyOn(auth, 'isValidPath').mockResolvedValue(true);
    vi.spyOn(auth, 'compressSession').mockRejectedValue(new Error('archiver blew up'));
    const warn = vi.fn();
    auth.log = { ...silentLogger, warn };

    await expect(auth.storeRemoteSession({ emit: false })).resolves.toBeUndefined();
    expect(warn).toHaveBeenCalledWith(
      expect.objectContaining({ err: 'archiver blew up' }),
      'session backup failed',
    );
  });
});
