<?php
declare(strict_types=1);

/**
 * admin-create-user.php — создаёт администратора в коллекции users.
 *
 * Usage:
 *   php scripts/admin-create-user.php --email=mz@iteloclub.com --role=admin
 *   php scripts/admin-create-user.php --email=mz@iteloclub.com --password='secret'
 *
 * Если --password не задан — будет запрошен интерактивно.
 */

require_once __DIR__ . '/../admin/vendor/autoload.php';

use MongoDB\BSON\UTCDateTime;

$config = require __DIR__ . '/../admin/config/config.php';

$opts = getopt('', ['email:', 'password::', 'role::', 'locale::']);
$email = strtolower(trim((string)($opts['email'] ?? '')));
if ($email === '') {
    fwrite(STDERR, "Usage: php scripts/admin-create-user.php --email=ADDR [--password=...] [--role=admin] [--locale=ru]\n");
    exit(1);
}

$password = $opts['password'] ?? null;
if ($password === null) {
    fwrite(STDERR, "Password: ");
    system('stty -echo');
    $password = trim((string)fgets(STDIN));
    system('stty echo');
    fwrite(STDERR, "\n");
}

if (strlen((string)$password) < 8) {
    fwrite(STDERR, "Password must be at least 8 chars\n");
    exit(2);
}

$role = $opts['role'] ?? 'admin';
$locale = $opts['locale'] ?? 'ru';

$client = new MongoDB\Client($config['mongo']['uri']);
$users = $client->selectDatabase($config['mongo']['database'])->selectCollection('users');

$now = new UTCDateTime();
$result = $users->updateOne(
    ['email' => $email],
    ['$set' => [
        'email' => $email,
        'passHash' => password_hash((string)$password, PASSWORD_BCRYPT),
        'role' => $role,
        'locale' => $locale,
        'updatedAt' => $now,
    ], '$setOnInsert' => ['createdAt' => $now]],
    ['upsert' => true]
);

echo "User {$email} " . ($result->getUpsertedCount() ? 'created' : 'updated') . ".\n";
