'use strict';

const fs = require('fs');
const path = require('path');
const os = require('os');
const { MongoClient } = require('mongodb');
const MongoStore = require('../src/lib/MongoStore');

const MONGO_URL = process.env.TEST_MONGO_URL;
const runIfMongo = MONGO_URL ? describe : describe.skip;

runIfMongo('MongoStore (integration)', () => {
  let client;
  let db;
  let store;
  let tmpDir;

  beforeAll(async () => {
    client = new MongoClient(MONGO_URL);
    await client.connect();
    db = client.db('iqteco_wa_test');
    tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'wa-store-'));
  });

  afterAll(async () => {
    if (db) await db.dropDatabase().catch(() => {});
    if (client) await client.close();
    if (tmpDir) fs.rmSync(tmpDir, { recursive: true, force: true });
  });

  beforeEach(async () => {
    await db.dropDatabase();
    // Fixtures are a few bytes long; the empty-session guard is exercised separately.
    store = new MongoStore({ db, dataPath: tmpDir, idInstance: '1101000001', minSaveBytes: 0 });
  });

  function writeZip(session, content = 'PK fake zip content') {
    const filename = path.join(tmpDir, `${session}.zip`);
    fs.writeFileSync(filename, content);
    return filename;
  }

  it('sessionExists returns false when no session saved', async () => {
    expect(await store.sessionExists({ session: 'RemoteAuth-test' })).toBe(false);
  });

  it('save → sessionExists returns true', async () => {
    writeZip('RemoteAuth-test', 'session-data-v1');
    await store.save({ session: 'RemoteAuth-test' });
    expect(await store.sessionExists({ session: 'RemoteAuth-test' })).toBe(true);
  });

  it('save → extract returns identical file', async () => {
    writeZip('RemoteAuth-test', 'session-data-v1-payload-xyz');
    await store.save({ session: 'RemoteAuth-test' });

    const outPath = path.join(tmpDir, 'extracted.zip');
    await store.extract({ session: 'RemoteAuth-test', path: outPath });

    expect(fs.readFileSync(outPath, 'utf8')).toBe('session-data-v1-payload-xyz');
  });

  it('save throws if zip file missing', async () => {
    await expect(store.save({ session: 'RemoteAuth-missing' }))
      .rejects.toThrow(/zip file not found/);
  });

  it('extract throws if session not in store', async () => {
    await expect(store.extract({ session: 'RemoteAuth-missing', path: path.join(tmpDir, 'x.zip') }))
      .rejects.toThrow(/not found/);
  });

  it('delete removes all revisions', async () => {
    writeZip('RemoteAuth-test', 'v1');
    await store.save({ session: 'RemoteAuth-test' });

    expect(await store.sessionExists({ session: 'RemoteAuth-test' })).toBe(true);
    await store.delete({ session: 'RemoteAuth-test' });
    expect(await store.sessionExists({ session: 'RemoteAuth-test' })).toBe(false);
  });

  // A backup taken against an unpaired profile zips to ~1.4KB. Stored, it makes
  // sessionExists() report a session that cannot be restored — the trigger for
  // onQR's dead-session watchdog, and the fuel of the 2026-08 reset loop.
  it('refuses to store an empty-looking session', async () => {
    const guarded = new MongoStore({ db, dataPath: tmpDir, idInstance: 'y', minSaveBytes: 65536 });
    writeZip('RemoteAuth-empty', 'PK'.padEnd(1428, '\0'));
    await guarded.save({ session: 'RemoteAuth-empty' });
    expect(await guarded.sessionExists({ session: 'RemoteAuth-empty' })).toBe(false);
  });

  it('stores a session that clears the size floor', async () => {
    const guarded = new MongoStore({ db, dataPath: tmpDir, idInstance: 'y', minSaveBytes: 1024 });
    writeZip('RemoteAuth-real', 'x'.repeat(4096));
    await guarded.save({ session: 'RemoteAuth-real' });
    expect(await guarded.sessionExists({ session: 'RemoteAuth-real' })).toBe(true);
  });

  it('keeps only last N revisions', async () => {
    const storeN = new MongoStore({ db, dataPath: tmpDir, revisionsToKeep: 2, idInstance: 'x', minSaveBytes: 0 });
    for (let i = 0; i < 5; i++) {
      writeZip('RemoteAuth-test', `v${i}`);
      await storeN.save({ session: 'RemoteAuth-test' });
    }
    const remaining = await db.collection('wa_sessions.files')
      .find({ filename: 'RemoteAuth-test.zip' })
      .toArray();
    expect(remaining.length).toBe(2);

    // latest is v4
    const outPath = path.join(tmpDir, 'latest.zip');
    await storeN.extract({ session: 'RemoteAuth-test', path: outPath });
    expect(fs.readFileSync(outPath, 'utf8')).toBe('v4');
  });
});
