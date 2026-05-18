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
    store = new MongoStore({ db, dataPath: tmpDir, idInstance: '1101000001' });
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

  it('keeps only last N revisions', async () => {
    const storeN = new MongoStore({ db, dataPath: tmpDir, revisionsToKeep: 2, idInstance: 'x' });
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
