#!/bin/bash
set -e

echo "[replica-init] Waiting for primary to be reachable..."
until mysql -h mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" -e "SELECT 1" >/dev/null 2>&1; do
    echo "[replica-init] Primary not ready yet, retrying..."
    sleep 2
done

echo "[replica-init] Primary is up. Dumping..."
mysqldump \
    -h mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" \
    --single-transaction \
    --source-data=2 \
    --databases 4th_year_project_db \
    > /tmp/dump.sql

LOG_FILE=$(grep "MASTER_LOG_FILE" /tmp/dump.sql | sed "s/.*MASTER_LOG_FILE='\\([^']*\\)'.*/\\1/")
LOG_POS=$(grep  "MASTER_LOG_POS"  /tmp/dump.sql | sed "s/.*MASTER_LOG_POS=\\([0-9]*\\).*/\\1/")

echo "[replica-init] Importing dump (file=${LOG_FILE}, pos=${LOG_POS})..."
mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" < /tmp/dump.sql

echo "[replica-init] Configuring replication..."
mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
CHANGE MASTER TO
    MASTER_HOST='mysql',
    MASTER_USER='root',
    MASTER_PASSWORD='${MYSQL_ROOT_PASSWORD}',
    MASTER_LOG_FILE='${LOG_FILE}',
    MASTER_LOG_POS=${LOG_POS};
START SLAVE;
SQL

echo "[replica-init] Replication started. All done."
