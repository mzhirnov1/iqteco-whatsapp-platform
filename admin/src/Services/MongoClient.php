<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Services;

use MongoDB\Client;
use MongoDB\Database;

final class MongoClient
{
    private static ?Database $database = null;

    public static function db(array $config): Database
    {
        if (self::$database === null) {
            $client = new Client($config['mongo']['uri'], [], [
                'typeMap' => [
                    'array' => 'array',
                    'document' => 'array',
                    'root' => 'array',
                ],
            ]);
            self::$database = $client->selectDatabase($config['mongo']['database']);
        }
        return self::$database;
    }

    public static function reset(): void
    {
        self::$database = null;
    }
}
