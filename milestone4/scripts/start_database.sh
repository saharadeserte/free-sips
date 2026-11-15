#!/usr/bin/env bash
set -u

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT/db" || exit 1

# Load consumer credentials (gitignored)
if [ -f .env ]; then
    set -a
    . ./.env
    set +a
fi

echo "Starting MariaDB"
sudo -n systemctl start mariadb || { echo "[!] MariaDB failed to start"; exit 1; }

# Stop any old consumer so starting twice doesn't create two
pkill -f '[a]mqp_consumer.php' 2>/dev/null || true

echo "Starting AMQP consumer"
# bedb.rpc auth worker
nohup php amqp_comsumer.php > /tmp/auth_worker.log 2>&1 < /dev/null &   

 # q_central_logging
nohup php amqp_consumer.php > /tmp/amqp.log 2>&1 < /dev/null &         
echo "All services are currently running"
