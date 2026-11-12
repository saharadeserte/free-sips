#!/usr/bin/env bash
# compose.sh - start or stop the cluster from any machine
#
#   ./compose.sh start [service ...]
#   ./compose.sh stop  [service ...]
#
# Override any value per run with <SERVICE>_USER, <SERVICE>_HOST, <SERVICE>_PORT,
# <SERVICE>_REPO (SERVICE = name with dashes removed, uppercased).
# Example: BEDB_HOST=100.x.y.z ./compose.sh stop be-db

set -u

ACTION="${1:-}"
case "$ACTION" in
    start|stop) shift ;;
    *) echo "Usage: $0 start|stop [service ...]"; exit 1 ;;
esac

# Start order. Stop runs the list in reverse.
ORDER=(rabbitmq database be-db be-fe frontend)

# Repo path. - change based on each remote user's dir
REPO="Capstone-Group-01/$MILESTONE"

declare -A SVC_USER SVC_HOST SVC_PORT SVC_REPO SVC_START SVC_STOP

# define <name> <user> <host> <ssh port> <repo> <start cmd> <stop cmd>
define() {
    local name="$1" user="$2" host="$3" port="$4" repo="$5" start="$6" stop="$7"
    local key="${name^^}"; key="${key//-/}"
    local var
    var="${key}_USER"; SVC_USER[$name]="${!var:-$user}"
    var="${key}_HOST"; SVC_HOST[$name]="${!var:-$host}"
    var="${key}_PORT"; SVC_PORT[$name]="${!var:-$port}"
    var="${key}_REPO"; SVC_REPO[$name]="${!var:-$repo}"
    SVC_START[$name]="$start"
    SVC_STOP[$name]="$stop"
}

#  name       user        host           port  repo    start command                           stop command
define rabbitmq  jja       100.100.36.22  22    "$REPO" "bash scripts/start_messenger.sh"    "sudo -n systemctl stop rabbitmq-server || true"
define database  it490sfc  100.123.138.94 22    "$REPO" "bash scripts/start_database.sh"     pkill -f '[a]mqp_co[nm]s' || true"
define be-db     pablob123 100.127.68.78  22    "$REPO" "bash scripts/start_be-db.sh"       "pkill -x dotnet || true"
define be-fe     befe      100.79.8.94    2223  "$REPO" "bash scripts/start_be-fe.sh"       "sudo -n systemctl stop cocktail-backend 2>/dev/null || true"
define frontend  frontend  100.82.21.127  22    "$REPO" "bash scripts/start_fe.sh"          "fuser -k 7812/tcp 2>/dev/null || true"

# True if $1 is an address this machine owns.
is_local() {
    local host="$1"
    tailscale ip -4 2>/dev/null | grep -qxF "$host" && return 0
    hostname -I 2>/dev/null | tr ' ' '\n' | grep -qxF "$host" && return 0
    return 1
}

# Builds the snippet that runs on the target machine.
build_body() {
    local action="$1" name="$2" repo="${SVC_REPO[$2]}"
    if [[ "$action" == start ]]; then
        printf 'cd ~/%q && { nohup bash -lc %q > /tmp/%s.log 2>&1 < /dev/null & }' \
            "$repo" "${SVC_START[$name]}" "$name"
    else
        printf 'cd ~/%q && bash -lc %q' "$repo" "${SVC_STOP[$name]}"
    fi
}

run_on() {
    local user="$1" host="$2" port="$3" body="$4"
    if is_local "$host"; then
        bash -c "$body"
    else
        ssh -n -p "$port" -o BatchMode=yes -o ConnectTimeout=5 -o StrictHostKeyChecking=accept-new \
            "$user@$host" "$body"
    fi
}

TARGETS=("$@")
[[ ${#TARGETS[@]} -eq 0 ]] && TARGETS=("${ORDER[@]}")

for t in "${TARGETS[@]}"; do
    if [[ -z "${SVC_HOST[$t]:-}" ]]; then
        echo "[!] Unknown service: $t (valid: ${ORDER[*]})"
        exit 1
    fi
done

if [[ "$ACTION" == stop ]]; then
    REV=()
    for (( i=${#TARGETS[@]}-1; i>=0; i-- )); do REV+=("${TARGETS[i]}"); done
    TARGETS=("${REV[@]}")
fi

FAILED=()
for name in "${TARGETS[@]}"; do
    user="${SVC_USER[$name]}"; host="${SVC_HOST[$name]}"; port="${SVC_PORT[$name]}"
    echo "--- $ACTION $name ($user@$host:$port, repo ${SVC_REPO[$name]}) ---"

    body=$(build_body "$ACTION" "$name")
    if run_on "$user" "$host" "$port" "$body"; then
        echo "    [OK]"
        if [[ "$ACTION" == start ]]; then
            sleep 2
            run_on "$user" "$host" "$port" "tail -n 3 /tmp/$name.log" | sed 's/^/      /'
        fi
    else
        echo "    [!] FAILED"
        FAILED+=("$name")
    fi
done

echo
if [[ ${#FAILED[@]} -eq 0 ]]; then
    echo "Done: $ACTION (${TARGETS[*]})."
else
    echo "Failed: ${FAILED[*]}"
    exit 1
fi
