// mongo-init.js — создание базы, индексов и users
// Запуск: mongosh "mongodb://localhost:27017" < deploy/scripts/mongo-init.js
// Опционально передать пароли через env: ADMIN_PWD, INSTANCE_PWD

const DB_NAME = 'iqteco_wa';

const adminPassword = (typeof process !== 'undefined' && process.env && process.env.ADMIN_PWD) || 'CHANGE_ME_ADMIN';
const instancePassword = (typeof process !== 'undefined' && process.env && process.env.INSTANCE_PWD) || 'CHANGE_ME_INSTANCE';

const db = db.getSiblingDB(DB_NAME);

// --- Indexes ---
print('[mongo-init] creating indexes');

db.instances.createIndex({ idInstance: 1 }, { unique: true });
db.instances.createIndex({ ownerId: 1 });
db.instances.createIndex({ state: 1 });
db.instances.createIndex({ ipv6: 1 });

db.ip_pool.createIndex({ ipv6: 1 }, { unique: true });
db.ip_pool.createIndex({ status: 1 });

db.webhook_log.createIndex({ idInstance: 1, sentAt: -1 });
db.webhook_log.createIndex({ sentAt: 1 }, { expireAfterSeconds: 60 * 60 * 24 * 30 });

db.webhook_outbox.createIndex({ idInstance: 1, status: 1, nextAttemptAt: 1 });

db.traffic.createIndex({ idInstance: 1, bucket: 1, periodKey: 1 }, { unique: true });

db.users.createIndex({ email: 1 }, { unique: true });

db._counters.createIndex({ name: 1 }, { unique: true });

// --- Counters bootstrap ---
print('[mongo-init] seeding counters');
db._counters.updateOne(
    { name: 'idInstance' },
    { $setOnInsert: { name: 'idInstance', seq: 1101000000 } },
    { upsert: true }
);

// --- Users ---
print('[mongo-init] creating db users (idempotent)');

function ensureUser(username, password, roles) {
    try {
        db.createUser({ user: username, pwd: password, roles });
        print(`  user ${username} created`);
    } catch (e) {
        if (e.codeName === 'DuplicateKey' || /already exists/.test(e.message)) {
            db.updateUser(username, { roles });
            print(`  user ${username} updated`);
        } else {
            throw e;
        }
    }
}

ensureUser('wa_admin', adminPassword, [
    { role: 'readWrite', db: DB_NAME },
]);

ensureUser('wa_instance', instancePassword, [
    { role: 'readWrite', db: DB_NAME },
]);

print('[mongo-init] done. DB: ' + DB_NAME);
print('[mongo-init] users: wa_admin, wa_instance (CHANGE DEFAULT PASSWORDS)');
