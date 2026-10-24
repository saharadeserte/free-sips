#!/usr/bin/env bash
# Usage: ./compose.sh start|stop
ACTION="${1:-}"
[[ "$ACTION" == "start" || "$ACTION" == "stop" ]] || { echo "Usage: $0 start|stop"; exit 1; }

# BUG FIX: Use single quotes so the '~' expands correctly on the remote machines, not your local machine.
# BUG FIX: Updated path to milestone3
REPO_BASE='~/Capstone-Group-01/milestone3'

# Cluster Service Table
# BUG FIX: Updated script names to start_be-fe.sh, start_be-db.sh, and start_fe.sh
# BUG FIX: Added -n to sudo so it fails instantly if it asks for a password instead of freezing.
# BUG FIX: Changed Pablo's kill command to 'pkill -x dotnet' to avoid killing SSH sessions.

# Cluster Service Table
SERVICES=(
  "RabbitMQ|jja@100.100.36.22|bash ~/Capstone-Group-01/milestone2/messaging/start_messenger.sh|sudo -n systemctl stop rabbitmq-server"
  "Database|it490sfc@100.123.138.94|bash ~/Capstone-Group-01/milestone2/db/start_database.sh|pkill -f amqp_comsumer.php"
  "Backend-DB|pablob123@100.127.68.78|bash ~/Capstone-Group-01/milestone3/BackendDatabase/start_be-db.sh|pkill -x dotnet"
  "Backend-FE|awdharry@100.110.200.67|bash ~/Capstone-Group-01/milestone3/be/BackendFrontend/start_be-fe.sh|sudo -n systemctl stop cocktail-backend"
  "FrontEnd|local|nohup ./start_fe.sh > fe.log 2>&1 </dev/null &|fuser -k 7812/tcp 2>/dev/null || true"
)

SSH="ssh -n -o BatchMode=yes -o ConnectTimeout=5 -o StrictHostKeyChecking=accept-new"
FAILED=()

# Run relative to script's directory
cd "$(dirname "$0")" || exit 1

if [[ "$ACTION" == "stop" ]]; then
  mapfile -t SERVICES < <(printf '%s\n' "${SERVICES[@]}" | tac)
fi

for entry in "${SERVICES[@]}"; do
  IFS='|' read -r name host start stop <<< "$entry"
  cmd=$([[ "$ACTION" == "start" ]] && echo "$start" || echo "$stop")
  echo "--- $ACTION $name ($host) ---"

  if [[ "$host" == "local" ]]; then
    if [[ "$ACTION" == "start" ]]; then
      # Run in background to prevent orchestrator block
      nohup ./start_fe.sh > fe.log 2>&1 </dev/null &
      rc=$?
    else
      eval "$cmd"
      rc=0
    fi
  else
    $SSH "$host" "$cmd"
    rc=$?
  fi

  if [[ $rc -eq 0 ]]; then
    echo "    [OK]"
  else
    echo "    [!] FAILED (exit $rc)"
    FAILED+=("$name")
  fi
done

echo
if [[ ${#FAILED[@]} -eq 0 ]]; then
  echo "All services: $ACTION done."
else
  echo "Failed services: ${FAILED[*]}"
  exit 1
fi
