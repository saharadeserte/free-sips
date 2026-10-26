#!/usr/bin/env bash
set -e

# Load Tailscale IP addresses from the cluster machine table
RABBITMQ_IP="100.100.36.22"       # rabbit (Jeremey)
DATABASE_IP="100.123.138.94"      # database (Steven)
BACKEND_FE_IP="100.110.200.67"    # awdharry (Harry - be-fb)
BACKEND_DB_IP="100.127.68.78"     # pabloserver (Pablo - be-db)
FRONTEND_IP="100.82.21.127";    

# Remote VM Usernames
RABBITMQ_USER="jja"
DATABASE_USER="it490sfc"
BACKEND_FE_USER="awdharry"
BACKEND_DB_USER="pablob123"
FRONTEND_USER="frontend"

# SSH options enforcing key authentication
SSH_OPTS="-o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=5"

REPO_BASE="~/Capstone-Group-01/milestone2"

echo "=================================================="
echo " Starting Distributed Services..."
echo "=================================================="

echo "[1/5] --- Starting RabbitMQ Service ($RABBITMQ_IP) ---"
ssh $SSH_OPTS ${RABBITMQ_USER}@${RABBITMQ_IP} "sudo systemctl start rabbitmq-server || bash ${REPO_BASE}/rmq/start_rmq.sh" || {
    echo "[!] Warning: Unable to start RMQ service remotely. Check SSH keys or host status."
}

echo "[2/5] --- Starting Database Service ($DATABASE_IP) ---"
ssh $SSH_OPTS ${DATABASE_USER}@${DATABASE_IP} "bash ${REPO_BASE}/db/start_database.sh" || {
    echo "[!] Warning: Unable to trigger Database start script."
}

echo "[3/5] --- Starting Backend-Frontend Service ($BACKEND_FE_IP) ---"
ssh $SSH_OPTS ${BACKEND_FE_USER}@${BACKEND_FE_IP} "bash ${REPO_BASE}/be/start_befe.sh" || {
    echo "[!] Warning: Unable to trigger BE-FE start script."
}

echo "[4/5] --- Starting Frontend Service ($FRONTEND_IP) ---"
ssh $SSH_OPTS ${FRONTEND_USER}@${FRONTEND_IP} "bash ${REPO_BASE}/fe/start_fe.sh" || {
    echo "[!] Warning: Unable to trigger FE start script."
}

echo "[5/5] --- Starting BE-DB Service (Locally on Port 7812) ---"
bash "$(dirname "$0")/start_be-db.sh"

echo "All services initiated."