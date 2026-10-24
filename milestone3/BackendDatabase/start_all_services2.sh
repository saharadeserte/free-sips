#!/usr/bin/env bash
set -u

DATABASE_IP="100.123.138.94";   DATABASE_USER="it490sfc";   DATABASE_REPO="Capstone-Group-01/milestone2"
BACKEND_FE_IP="100.110.200.67"; BACKEND_FE_USER="awdharry"; BACKEND_FE_REPO="Capstone-Group-01/milestone2"
RABBITMQ_IP="100.100.36.22";    RABBITMQ_USER="jja";        RABBITMQ_REPO="Capstone-Group-01/milestone2"
FRONTEND_IP="100.82.21.127";    FRONTEND_USER="frontend";   FRONTEND_REPO="Capstone-Group-01/milestone2"

SSH_OPTS="-n -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=5"

start_remote() {
    local label="$1" user="$2" ip="$3" repo="$4" dir="$5" script="$6"
    echo "--- Starting $label ($ip) ---"

   local remote_cmd="cd "$HOME/${repo}/${dir}" && { setsid nohup bash -lc 'bash ./${script}' > /tmp/${label}.log 2>&1 < /dev/null & }"
    if ssh $SSH_OPTS "${user}@${ip}" "$remote_cmd"; then
        sleep 2
        echo "    launched; remote log:"
        ssh $SSH_OPTS "${user}@${ip}" "tail -n 5 /tmp/${label}.log" | sed 's/^/      /'
    else
        echo "[!] Could not run $label at $ip (SSH, Tailscale, or wrong path)."
    fi
}

echo "[1/5] --- Starting Backend Database Server ---"
BEDB_SCRIPT="$(dirname "$0")/start_be-db.sh"
if [ -f "$BEDB_SCRIPT" ]; then
    nohup bash "$BEDB_SCRIPT" > /tmp/be-db.log 2>&1 &
    echo "    launched (log: /tmp/be-db.log)"
else
    echo "[!] $BEDB_SCRIPT not found."
fi
echo "[4/5]"; start_remote rabbitmq    "$RABBITMQ_USER" "$RABBITMQ_IP" \
"$RABBITMQ_REPO" "messaging"   start_messenger.sh
echo "[2/5]"; start_remote database "$DATABASE_USER"  "$DATABASE_IP"   \
"$DATABASE_REPO"   "db"                       start_database.sh
echo "[3/5]"; start_remote be-fe    "$BACKEND_FE_USER" "$BACKEND_FE_IP" \
"$BACKEND_FE_REPO" "backend/BackendFrontend"   start_be-fe.sh
echo "[5/5]"; start_remote fe       "$FRONTEND_USER"   "$FRONTEND_IP"  \
 "$FRONTEND_REPO"   "fe"                       start_fe.sh


echo "All services initiated."