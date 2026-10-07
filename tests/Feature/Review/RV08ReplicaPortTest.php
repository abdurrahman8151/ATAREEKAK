<?php

namespace Tests\Feature\Review;

use Tests\TestCase;

/**
 * RV-08: `DB_REPLICA_PORT` was set in three places and read in NONE of them.
 *
 * `config/database.php` honoured the replica HOST but not the replica PORT, so in production
 * every read connected to the replica host on the PRIMARY's port. That is only correct while both
 * listeners share a port.
 *
 * This test loads the real config file fresh with a controlled `$_SERVER`, because the file's
 * production guard reads `$_SERVER['APP_ENV']` directly (config files are evaluated while the
 * container is being built, so `app()` is unavailable there - see the comment in database.php).
 * Re-reading the file is the only way to observe the production branch at all: by the time a test
 * runs, config is already cached from the non-production environment.
 *
 * The read config is resolved with `array_merge($config, $config['read'])`, which is exactly what
 * `Illuminate\Database\Connectors\ConnectionFactory::mergeReadWriteConfig()` does (vendor line 153).
 * Asserting on that merge is asserting on what Laravel will really connect with.
 */
class RV08ReplicaPortTest extends TestCase
{
    /** @var array<string, array{server: mixed, env: mixed, getenv: string|false}> */
    private array $savedServer = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['APP_ENV', 'DB_HOST', 'DB_PORT', 'DB_REPLICA_HOST', 'DB_REPLICA_PORT'] as $key) {
            $this->savedServer[$key] = [
                'server' => $_SERVER[$key] ?? null,
                'env' => $_ENV[$key] ?? null,
                'getenv' => getenv($key),
            ];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedServer as $key => $saved) {
            if ($saved['server'] === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $saved['server'];
            }

            if ($saved['env'] === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $saved['env'];
            }

            if ($saved['getenv'] === false) {
                putenv($key);
            } else {
                putenv($key.'='.$saved['getenv']);
            }
        }

        parent::tearDown();
    }

    /**
     * Set or clear an env var across ALL THREE channels `env()` reads: $_SERVER, $_ENV and
     * getenv(). Clearing only one of them silently leaves the old value visible, which is what
     * makes the "unset" assertions in this file meaningful rather than accidental.
     */
    private function putEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);

            return;
        }

        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
        putenv($key.'='.$value);
    }

    /**
     * Load config/database.php under a controlled environment and resolve the effective
     * read + write connection configs the way Laravel's ConnectionFactory does.
     *
     * @return array{read: array<string, mixed>, write: array<string, mixed>}
     */
    private function resolve(array $env): array
    {
        foreach ($env as $key => $value) {
            $this->putEnv($key, $value);
        }

        $config = require base_path('config/database.php');
        $mysql = $config['connections']['mysql'];

        $resolveOne = static fn (string $type): array => array_merge(
            $mysql,
            $mysql[$type]
        );

        return ['read' => $resolveOne('read'), 'write' => $resolveOne('write')];
    }

    public function test_production_read_uses_the_replica_port(): void
    {
        $resolved = $this->resolve([
            'APP_ENV' => 'production',
            'DB_HOST' => 'primary.example',
            'DB_PORT' => '3306',
            'DB_REPLICA_HOST' => 'replica.example',
            'DB_REPLICA_PORT' => '4406',
        ]);

        $this->assertSame('replica.example', $resolved['read']['host'][0]);
        $this->assertSame(
            '4406',
            $resolved['read']['port'][0],
            'In production a read must use DB_REPLICA_PORT, not the primary port.'
        );
    }

    public function test_write_still_uses_the_primary_port(): void
    {
        $resolved = $this->resolve([
            'APP_ENV' => 'production',
            'DB_HOST' => 'primary.example',
            'DB_PORT' => '3306',
            'DB_REPLICA_HOST' => 'replica.example',
            'DB_REPLICA_PORT' => '4406',
        ]);

        $this->assertSame('primary.example', $resolved['write']['host'][0]);
        $this->assertSame(
            '3306',
            $resolved['write']['port'],
            'A read/write split must never move writes onto the replica.'
        );
    }

    public function test_production_falls_back_to_the_primary_port_when_replica_port_is_unset(): void
    {
        $resolved = $this->resolve([
            'APP_ENV' => 'production',
            'DB_HOST' => 'primary.example',
            'DB_PORT' => '3306',
            'DB_REPLICA_HOST' => 'replica.example',
            'DB_REPLICA_PORT' => null,
        ]);

        $this->assertSame(
            '3306',
            $resolved['read']['port'][0],
            'An operator who never sets DB_REPLICA_PORT must keep exactly today\'s behaviour.'
        );
    }

    public function test_local_and_testing_ignore_the_replica_port_entirely(): void
    {
        foreach (['local', 'testing'] as $appEnv) {
            $resolved = $this->resolve([
                'APP_ENV' => $appEnv,
                'DB_HOST' => '127.0.0.1',
                'DB_PORT' => '3399',
                'DB_REPLICA_HOST' => 'replica.example',
                'DB_REPLICA_PORT' => '4406',
            ]);

            $this->assertSame('127.0.0.1', $resolved['read']['host'][0], "$appEnv reads locally");
            $this->assertSame(
                '3399',
                $resolved['read']['port'][0],
                "$appEnv must ignore DB_REPLICA_PORT so the suite keeps one transactional connection."
            );
        }
    }

    /**
     * The guard that matters most: the host and the port must never disagree about which
     * environment they are in. A read that pairs the replica HOST with the local PORT is exactly
     * the misconfiguration this change exists to prevent.
     */
    public function test_replica_host_and_replica_port_are_always_selected_together(): void
    {
        $prod = $this->resolve([
            'APP_ENV' => 'production',
            'DB_HOST' => 'primary.example',
            'DB_PORT' => '3306',
            'DB_REPLICA_HOST' => 'replica.example',
            'DB_REPLICA_PORT' => '4406',
        ]);

        $this->assertSame('replica.example', $prod['read']['host'][0]);
        $this->assertSame('4406', $prod['read']['port'][0]);

        $local = $this->resolve([
            'APP_ENV' => 'testing',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3399',
            'DB_REPLICA_HOST' => 'replica.example',
            'DB_REPLICA_PORT' => '4406',
        ]);

        $this->assertSame('127.0.0.1', $local['read']['host'][0]);
        $this->assertSame('3399', $local['read']['port'][0]);
    }
}
