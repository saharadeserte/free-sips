#!/usr/bin/env bash
set -u

RABBITMQ_IP="100.100.36.22";    RABBITMQ_USER="jja"
DATABASE_IP="100.123.138.94";   DATABASE_USER="it490sfc"
BACKEND_DB_IP="100.127.68.78";  BACKEND_DB_USER="pabloserver"
FRONTEND_IP="100.82.21.127";    FRONTEND_USER="frontend"

SSH_OPTS="-n -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=5"

# Directory containing rmq/, db/, be/ (the ~ expands on each remote machine)
REPO_BASE="~/Capstone-Group-01/milestone2/messaging"

# start_remote <label> <user> <ip> <command>
start_remote() {
    local label="$1" user="$2" ip="$3" cmd="$4"
    echo "--- Starting $label ($ip) ---"
    if ssh $SSH_OPTS "${user}@${ip}" "nohup bash -c '$cmd' > /tmp/${label}.log 2>&1 &"; then
        echo "    launched (remote log: /tmp/${label}.log)"
    else
        echo "[!] Warning: could not reach $label at $ip (check SSH key, Tailscale, host status)."
    fi
}

echo "[1/5]"; start_remote rabbitmq "$RABBITMQ_USER" "$RABBITMQ_IP" \
    "sudo -n systemctl start rabbitmq-server || bash ${REPO_BASE}/rmq/start_rmq.sh"
echo "[2/5]"; start_remote database "$DATABASE_USER" "$DATABASE_IP" \
    "bash ${REPO_BASE}/db/start_database.sh"
echo "[3/5]"; start_remote be-db "$BACKEND_DB_USER" "$BACKEND_DB_IP" \
    "bash ${REPO_BASE}/be/start_bedb.sh"
echo "[4/5]"; start_remote fe "$FRONTEND_USER" "$FRONTEND_IP" \
    "bash ${REPO_BASE}/fe/start_fe.sh"

echo "[5/5] --- Starting Backend-FrontEnd ---"
BEFE_SCRIPT="$(dirname "$0")/start_be-fe.sh"
if [ -f "$BEFE_SCRIPT" ]; then
    nohup bash "$BEFE_SCRIPT" > /tmp/backendfrontend.log 2>&1 &
    echo "    launched (log: /tmp/backendfrontend.log)"
else
    echo "[!] $BEFE_SCRIPT not found."
fi

echo "All services initiated."

