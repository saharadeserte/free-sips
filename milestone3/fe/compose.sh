#!/usr/bin/env bash
# Usage: ./compose.sh start|stop
ACTION="${1:-}"
[[ "$ACTION" == "start" || "$ACTION" == "stop" ]] || { echo "Usage: $0 start|stop"; exit 1; }

REPO_BASE="~/Capstone-Group-01/milestone2"

# Non-interactive SSH skips .bashrc, ensure dotnet is in PATH on Pablo's VM
DOTNET_ENV='export PATH="$PATH:$HOME/.dotnet:/usr/share/dotnet:/usr/lib/dotnet" &&'

# Cluster Service Definition Table
# Format: "Service Name|user@ip (or 'local')|start command|stop command"
SERVICES=(
  "RabbitMQ|jja@100.100.36.22|sudo systemctl start rabbitmq-server|sudo systemctl stop rabbitmq-server"
  "Database|it490sfc@100.123.138.94|cd $REPO_BASE/db && nohup composer run start > db.log 2>&1 </dev/null &|pkill -f db_listener.php || pkill -f 'composer run start'"
  "Backend-DB|pablob123@100.127.68.78|cd $REPO_BASE/be && $DOTNET_ENV nohup dotnet run > bedb.log 2>&1 </dev/null &|pkill -f dotnet"
  "Backend-FE|awdharry@100.110.200.67|cd $REPO_BASE/be && nohup composer run start > befe.log 2>&1 </dev/null &|pkill -f server_befe.php || pkill -f 'composer run start'"
  "FrontEnd|local|./start_frontend.sh|fuser -k 7812/tcp 2>/dev/null || true"
)

SSH="ssh -n -o BatchMode=yes -o ConnectTimeout=5 -o StrictHostKeyChecking=accept-new"
FAILED=()

# Always run from the directory where compose.sh lives
cd "$(dirname "$0")" || exit 1

# Reverse execution order when stopping so FrontEnd drops first
if [[ "$ACTION" == "stop" ]]; then
  mapfile -t SERVICES < <(printf '%s\n' "${SERVICES[@]}" | tac)
fi

for entry in "${SERVICES[@]}"; do
  IFS='|' read -r name host start stop <<< "$entry"
  cmd=$([[ "$ACTION" == "start" ]] && echo "$start" || echo "$stop")
  echo "--- $ACTION $name ($host) ---"

  if [[ "$host" == "local" ]]; then
    if [[ "$ACTION" == "start" ]]; then
      # run start_frontend in background cleanly with logging
      nohup ./start_frontend.sh > fe.log 2>&1 </dev/null &
      rc=$?
    else
      # Terminate port 7812 listeners cleanly
      fuser -k 7812/tcp >/dev/null 2>&1 || true
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
