#!/usr/bin/env bash
# Usage: ./compose.sh start|stop|status
# Run from the FrontEnd VM. Starts/stops/checks every service in the cluster.
ACTION="${1:-}"
case "$ACTION" in start|stop|status) ;; *) echo "Usage: $0 start|stop|status"; exit 1 ;; esac

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ---- VERIFY these repo paths on each VM (the ~ expands on the REMOTE machine) ----
RMQ_DIR='~/Capstone-Group-01/milestone3/messaging'
DB_DIR='~/Capstone-Group-01/milestone3/db'
BEFE_DIR='~/Capstone-Group-01/milestone3/be/BackendFrontend'
BEDB_DIR='~/IT490/Capstone-Group-01/milestone3/be/BackendDatabase'

# Detached start: survives the SSH session ending, log goes to /tmp/<name>.log
bg() { echo "cd $1 && setsid nohup bash ./$2 > /tmp/$3.log 2>&1 < /dev/null &"; }

# Fields are separated by '#':  name # user@host (or "local") # start # stop # status
# status command must exit 0 only when the service is healthy.
SERVICES=(
  "RabbitMQ#jja@100.100.36.22#$(bg "$RMQ_DIR" start_messenger.sh rabbitmq)#sudo -n systemctl stop rabbitmq-server#systemctl is-active --quiet rabbitmq-server"
  "Database#it490sfc@100.123.138.94#$(bg "$DB_DIR" start_database.sh database)#pkill -f amqp_comsumer.php; sudo -n systemctl stop mariadb#pgrep -f amqp_comsumer.php"
  "Backend-DB#pablob123@100.127.68.78#$(bg "$BEDB_DIR" start_be-db.sh be-db)#pkill -x dotnet#pgrep -x dotnet"
  "Backend-FE#awdharry@100.110.200.67#$(bg "$BEFE_DIR" start_be-fe.sh be-fe)#sudo -n systemctl stop cocktail-backend#systemctl is-active --quiet cocktail-backend"
  "FrontEnd#local#cd \"$SCRIPT_DIR\" && setsid nohup ./start_fe.sh > fe.log 2>&1 < /dev/null &#fuser -k 7812/tcp#curl -fs --max-time 2 http://localhost:7812/raw"
)

SSH="ssh -n -o BatchMode=yes -o ConnectTimeout=5 -o StrictHostKeyChecking=accept-new"

run() { # run <host> <command>
  if [[ "$1" == "local" ]]; then bash -c "$2" >/dev/null 2>&1; else $SSH "$1" "$2" >/dev/null 2>&1; fi
}

is_up() { run "$1" "$2"; }

FAILED=()

if [[ "$ACTION" == "stop" ]]; then
  mapfile -t SERVICES < <(printf '%s\n' "${SERVICES[@]}" | tac)   # reverse order
fi

for entry in "${SERVICES[@]}"; do
  IFS='#' read -r name host start stop status <<< "$entry"

  case "$ACTION" in
    status)
      if is_up "$host" "$status"; then printf '  %-12s UP\n' "$name"; else printf '  %-12s DOWN\n' "$name"; fi
      ;;
    start)
      echo "--- start $name ($host) ---"
      if is_up "$host" "$status"; then echo "    already running [OK]"; continue; fi
      run "$host" "$start"
      ok=1
      for _ in 1 2 3 4 5 6 7 8 9 10; do   # wait up to ~20s for healthy
        sleep 2
        if is_up "$host" "$status"; then ok=0; break; fi
      done
      if [[ $ok -eq 0 ]]; then echo "    [OK] UP"; else echo "    [!] FAILED to come up (see /tmp on that host)"; FAILED+=("$name"); fi
      ;;
    stop)
      echo "--- stop $name ($host) ---"
      run "$host" "$stop"
      sleep 2
      if is_up "$host" "$status"; then echo "    [!] still running"; FAILED+=("$name"); else echo "    [OK] DOWN"; fi
      ;;
  esac
done

echo
if [[ "$ACTION" == "status" ]]; then exit 0; fi
if [[ ${#FAILED[@]} -eq 0 ]]; then echo "All services: $ACTION done."; else echo "Failed services: ${FAILED[*]}"; exit 1; fi