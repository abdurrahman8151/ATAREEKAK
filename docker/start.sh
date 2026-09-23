#!/bin/sh
set -e

APP_PORT="${PORT:-10000}"
# Always use at least 2 workers so health checks never starve
WORKERS="${WEB_CONCURRENCY:-2}"
if [ "$WORKERS" -lt 2 ]; then
    WORKERS=2
fi

echo "=== SyRide: port=${APP_PORT} workers=${WORKERS} ==="
echo "    PHP $(php -r 'echo phpversion();')"
echo "    Ext: $(php -m | grep -E '^(sockets|pcntl|redis|pdo_mysql)$' | tr '\n' ' ')"

# Discover packages & cache config
php artisan package:discover --ansi
php artisan config:cache
php artisan route:cache
php artisan storage:link --no-interaction 2>/dev/null || true

echo "=== Checking database readiness ==="
php -r '
$host = getenv("DB_HOST");
$port = getenv("DB_PORT") ?: "3306";
$db   = getenv("DB_DATABASE");
$user = getenv("DB_USERNAME");
$pass = getenv("DB_PASSWORD");

if (empty($host) || $host === "127.0.0.1" || $host === "localhost") {
    echo "Local database or no DB_HOST configured.\n";
    passthru("php artisan migrate --force", $code);
    exit(0);
}

$retries = 8;
$connected = false;

while ($retries > 0) {
    $ip = gethostbyname($host);
    if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
        echo "DNS resolution for host \"{$host}\" not ready yet. Retrying in 3s... ({$retries} attempts left)\n";
    } else {
        echo "Resolved {$host} -> {$ip}. Checking database connection...\n";
        try {
            $options = [
                PDO::ATTR_TIMEOUT => 4,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ];
            if (file_exists("/etc/ssl/certs/ca-certificates.crt")) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = "/etc/ssl/certs/ca-certificates.crt";
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }
            $pdo = new PDO("mysql:host={$host};port={$port};dbname={$db}", $user, $pass, $options);
            echo "Database connection successful!\n";
            $connected = true;
            break;
        } catch (\Throwable $e) {
            echo "Database connect attempt failed: " . $e->getMessage() . " ({$retries} attempts left)\n";
        }
    }
    sleep(3);
    $retries--;
}

if ($connected) {
    echo "Running migrations...\n";
    passthru("php artisan migrate --force", $code);
} else {
    echo "WARNING: Could not connect to database ({$host}:{$port}). Skipping migrations.\n";
}
'

echo "=== Checking Redis readiness ==="
php -r '
try {
    $redisUrl = getenv("REDIS_URL");
    if ($redisUrl) {
        $parts = parse_url($redisUrl);
        $host = ($parts["scheme"] === "rediss" ? "tls://" : "") . $parts["host"];
        $port = $parts["port"] ?? 6379;
        $pass = $parts["pass"] ?? null;
    } else {
        $host = getenv("REDIS_HOST") ?: "127.0.0.1";
        $port = (int)(getenv("REDIS_PORT") ?: 6379);
        $pass = getenv("REDIS_PASSWORD") ?: null;
    }

    echo "Testing Redis connection to {$host}:{$port}...\n";
    $redis = new Redis();
    $redis->connect($host, $port, 3.0); // 3 second timeout
    if ($pass) {
        $redis->auth($pass);
    }
    $redis->ping();
    echo "Redis connection successful!\n";
} catch (\Throwable $e) {
    echo "WARNING: Redis connection test failed: " . $e->getMessage() . "\n";
}
'

WORKER_SCRIPT="/var/www/html/vendor/bin/roadrunner-worker"
if [ ! -f "$WORKER_SCRIPT" ]; then
    WORKER_SCRIPT="/var/www/html/vendor/laravel/octane/bin/roadrunner-worker"
fi

echo "Using worker script: ${WORKER_SCRIPT}"

cat > /var/www/html/.rr.yaml << RRCFG
version: "3"

rpc:
  listen: "tcp://127.0.0.1:6001"

server:
  command: "php -d variables_order=EGPCS -d display_errors=stderr -d log_errors=1 -d error_log=/dev/stderr ${WORKER_SCRIPT}"
  relay: pipes
  relay_timeout: 30s

http:
  address: "0.0.0.0:${APP_PORT}"
  middleware: ["gzip"]
  trusted_subnets:
    - "10.0.0.0/8"
    - "172.16.0.0/12"
    - "192.168.0.0/16"
    - "127.0.0.1/8"
    - "fd00::/8"
    - "::1/128"
  pool:
    num_workers: ${WORKERS}
    max_jobs: 500
    allocate_timeout: 60s
    destroy_timeout: 30s
    supervisor:
      exec_ttl: 120s
      max_worker_memory: 256

logs:
  mode: production
  level: warn
  encoding: json
  output: stderr
RRCFG

echo "=== Starting RoadRunner on port ${APP_PORT} ==="
exec /var/www/html/rr serve -c /var/www/html/.rr.yaml
