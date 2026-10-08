<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for all database work. Of course
    | you may use many connections at once using the Database library.
    |
    */

    'default' => env('DB_CONNECTION', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Here are each of the database connections setup for your application.
    | Of course, examples of configuring each database platform that is
    | supported by Laravel is shown below to make development simple.
    |
    |
    | All database work in Laravel is done through the PHP PDO facilities
    | so make sure you have the driver for your particular database of
    | choice installed on your machine before you begin development.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DATABASE_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DATABASE_URL'),

            'read' => [
                /*
                 * RV-37: no read/write splitting outside production.
                 *
                 * With splitting on, a plain read such as User::where(...)->count()
                 * goes out on a SEPARATE PDO connection, so it cannot see the
                 * transaction RefreshDatabase opened. It sees whatever other tests had
                 * already committed instead — which is why aggregates were
                 * order-dependent: AdminDriverServiceTest reported 15 extra failures
                 * under --order-by=random, all of them count-style assertions
                 * (e.g. total_drivers = 2 where 0 was expected).
                 *
                 * Replica routing is a production scaling feature. A test needs one
                 * transactional connection, so local and testing read from the same
                 * host they write to.
                 *
                 * The check is $_SERVER rather than app()->environment(): config files
                 * are evaluated while the container is still being built, and calling
                 * app() there fails with "Target class [env] does not exist".
                 */
                'host' => [($_SERVER['APP_ENV'] ?? null) === 'production'
                    ? env('DB_REPLICA_HOST', env('DB_HOST', '127.0.0.1'))
                    : env('DB_HOST', '127.0.0.1')],

                /*
                 * RV-08: the replica's PORT was never read anywhere in this file.
                 *
                 * The replica HOST was honoured but the PORT silently was not, so every read
                 * went to the replica HOST on the PRIMARY's port. That is correct only for as
                 * long as both listeners share one port. The moment the replica listens
                 * elsewhere, a read either fails to connect or lands on the wrong instance -
                 * and because `sticky` and the `useWritePdo` calls in JwtService,
                 * UserRepository, WalletController and JwtAuthMiddleware are all built on the
                 * premise that reads reach a real replica, the failure would surface as stale
                 * auth/money reads rather than an obvious connection error.
                 *
                 * Three separate places already treat DB_REPLICA_PORT as a real setting:
                 * render.yaml declares it `sync: false` (so Render PROMPTS the operator for a
                 * value it never uses), phpunit.xml forces it, and scripts/scratch-env.ps1
                 * refuses to run when it disagrees with DB_PORT. This is the line that makes
                 * those three true rather than decorative.
                 *
                 * It belongs here because Laravel merges the WHOLE `read` array over the base
                 * config for read queries (ConnectionFactory::mergeReadWriteConfig ->
                 * array_merge($config, $merge)), so any key under `read` overrides the base.
                 *
                 * The guard mirrors the host one exactly - production only, and falls back to
                 * DB_PORT when unset - so local and testing are provably unchanged and an
                 * operator who never sets DB_REPLICA_PORT keeps today's behaviour.
                 */
                // BUG FIX (found by running `kyc:migrate-disk --dry-run`, which is the first thing
                // in this app that issues a SELECT before any write): this was
                // `'port' => [ ... ]`.
                //
                // Laravel supports an ARRAY for `host` on a read replica - a replica list is a set
                // of hosts to choose from. `port` is not one of them and must be a scalar.
                // `ConnectionFactory::mergeReadWriteConfig()` does array_merge($config, $read), so
                // the array REPLACED the base `port` outright, and `MySqlConnector` then
                // interpolated an array straight into the DSN: "Array to string conversion"
                // followed by "No connection could be made because the target machine actively
                // refused it".
                //
                // WHY NO TEST CAUGHT IT: `RefreshDatabase` runs migrations FIRST, which sets
                // Laravel's `recordsModified`, and `sticky => true` then pins every later query to
                // the WRITE PDO. The broken `read` config is never exercised - 900+ passing tests
                // all take the write path. In production the first query on a fresh connection is
                // frequently a READ (looking up the user to log them in), so this was live on every
                // read-before-write path.
                'port' => ($_SERVER['APP_ENV'] ?? null) === 'production'
                    ? env('DB_REPLICA_PORT', env('DB_PORT', '3306'))
                    : env('DB_PORT', '3306'),
            ],
            'write' => [
                'host' => [env('DB_HOST', '127.0.0.1')],
            ],
            'sticky' => true,

            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
            'options' => extension_loaded('pdo_mysql') ? (function () {
                $options = [
                    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => filter_var(env('MYSQL_ATTR_SSL_VERIFY_SERVER_CERT', false), FILTER_VALIDATE_BOOLEAN),
                ];
                $ca = env('MYSQL_ATTR_SSL_CA');
                if (! $ca && file_exists(storage_path('certs/aiven-ca.pem'))) {
                    $ca = storage_path('certs/aiven-ca.pem');
                } elseif (! $ca && file_exists('/etc/ssl/certs/ca-certificates.crt') && (env('DB_PORT') != 3306 || env('DB_SSL', false))) {
                    $ca = '/etc/ssl/certs/ca-certificates.crt';
                }
                if (! empty($ca)) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
                }

                return $options;
            })() : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run in the database.
    |
    */

    'migrations' => 'migrations',

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as APC or Memcached. Laravel makes it easy to dig right in.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_database_'),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'scheme' => env('REDIS_SCHEME', null),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', env('REDIS_DB', '0')),
            'scheme' => env('REDIS_SCHEME', null),
        ],

    ],

];
