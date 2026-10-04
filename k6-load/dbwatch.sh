#!/usr/bin/env bash
#
# dbwatch.sh - sample MySQL pressure DURING a k6 run (RV-17).
#
# WHY A SEPARATE SCRIPT. RV-17 asks for `threads_running` / lock-wait capture alongside the load
# numbers. k6 speaks HTTP only - it cannot query MySQL - so the database half of the measurement has
# to be sampled by something that can. This is that something.
#
# WHY IT MATTERS. An HTTP p95 on its own cannot distinguish "the application is slow" from "the
# database is serialising on a lock". The 95/5 settlement and the escrow paths take row locks
# (`lockForUpdate` in WalletTransactionService), so a run that shows rising latency with
# `Innodb_row_lock_waits` climbing has found a LOCK, not a slow endpoint - and those are fixed in
# completely different places.
#
# USAGE - run this in one terminal and k6 in another, so both cover the same window:
#
#   terminal 1:  ./k6-load/dbwatch.sh 90          # 90 samples, 1s apart
#   terminal 2:  k6 run --summary-export "perf-results/run-$(git rev-parse --short HEAD).json" \
#                  k6-load/perf-3run.js -e K6_GIT_SHA="$(git rev-parse --short HEAD)" \
#                  -e K6_PEOPLE="a@b.com:pw" -e BASE_URL=http://localhost:8000
#
# Credentials come from the environment, never from this file (RV-18: a real API key was once
# committed in phpunit.xml; the same mistake here would be worse, because this reads the production
# database's credentials).
#
#   DB_HOST DB_PORT DB_USERNAME DB_PASSWORD  - required
#
# Output is CSV to stdout AND appended to perf-results/dbwatch-<timestamp>.csv, so the DB series can
# be committed next to the HTTP series and compared later.

set -euo pipefail

SAMPLES="${1:-60}"
INTERVAL="${DBWATCH_INTERVAL:-1}"

: "${DB_HOST:?DB_HOST is required (the database host)}"
: "${DB_PORT:?DB_PORT is required}"
: "${DB_USERNAME:?DB_USERNAME is required}"
: "${DB_PASSWORD:?DB_PASSWORD is required}"

# Prefer the mysql client; fall back to a docker exec into the compose mysql service.
if command -v mysql >/dev/null 2>&1; then
    run_sql() { MYSQL_PWD="$DB_PASSWORD" mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USERNAME" --batch --skip-column-names -e "$1"; }
elif command -v docker >/dev/null 2>&1 && docker ps --format '{{.Names}}' 2>/dev/null | grep -q 'syride_mysql'; then
    run_sql() { docker exec -e MYSQL_PWD="$DB_PASSWORD" syride_mysql mysql --user="$DB_USERNAME" --batch --skip-column-names -e "$1"; }
else
    echo "dbwatch: need the mysql client, or a running syride_mysql container" >&2
    exit 1
fi

OUT_DIR="${K6_OUT_DIR:-perf-results}"
mkdir -p "$OUT_DIR"
STAMP="$(date +%Y%m%d-%H%M%S)"
CSV="${OUT_DIR}/dbwatch-${STAMP}.csv"

echo "ts,threads_running,threads_connected,row_lock_waits,row_lock_time_ms,slow_queries" > "$CSV"
echo "dbwatch: sampling ${SAMPLES}x at ${INTERVAL}s -> ${CSV}"

for i in $(seq 1 "$SAMPLES"); do
    # `SHOW GLOBAL STATUS` counters are monotonic, so the DELTA over the interval is what is
    # interesting: sample twice per row and subtract. Without that, a long run's cumulative totals
    # look like rising pressure when they are just accumulated.
    prev="$(run_sql "SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_running','Threads_connected','Innodb_row_lock_waits','Innodb_row_lock_time','Slow_queries');")"
    sleep "$INTERVAL"
    curr="$(run_sql "SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_running','Threads_connected','Innodb_row_lock_waits','Innodb_row_lock_time','Slow_queries');")"

    # Straightforward extraction rather than clever awk: correctness over brevity for a numbers
    # tool. `read_counter` pulls one metric out of the tab-separated SHOW GLOBAL STATUS output.
    read_counter() {
        printf '%s\n' "$1" | awk -F'\t' -v k="$2" '$1==k { print $2; found=1 } END { if (!found) print 0 }'
    }

    prev_waits="$(read_counter "$prev" Innodb_row_lock_waits)"
    curr_waits="$(read_counter "$curr" Innodb_row_lock_waits)"
    prev_locktime="$(read_counter "$prev" Innodb_row_lock_time)"
    curr_locktime="$(read_counter "$curr" Innodb_row_lock_time)"
    prev_slow="$(read_counter "$prev" Slow_queries)"
    curr_slow="$(read_counter "$curr" Slow_queries)"
    running="$(read_counter "$curr" Threads_running)"
    connected="$(read_counter "$curr" Threads_connected)"

    waits=$(( ${curr_waits:-0} - ${prev_waits:-0} ))
    # MySQL's Innodb_row_lock_time is ALREADY in milliseconds. Dividing it here (an earlier draft
    # did, while also labelling the column _ms) reported seconds under a millisecond heading - a
    # numbers tool must not disagree with its own units.
    lock_ms=$(( ${curr_locktime:-0} - ${prev_locktime:-0} ))
    slow=$(( ${curr_slow:-0} - ${prev_slow:-0} ))

    row="$(date -u +%Y-%m-%dT%H:%M:%SZ),${running:-0},${connected:-0},${waits},${lock_ms},${slow}"
    echo "$row"
    echo "$row" >> "$CSV"

    if [ "${waits:-0}" -gt 0 ]; then
        echo "  ^ ${waits} row-lock wait(s), ${lock_ms}ms blocked in the last ${INTERVAL}s -" >&2
        echo "    the database is serialising. Fix that, not the endpoint p95." >&2
    fi
done

echo "dbwatch: done -> ${CSV}"
