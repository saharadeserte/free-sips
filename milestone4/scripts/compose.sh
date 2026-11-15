#!/usr/bin/env bash
# compose.sh - start, stop, or check the cluster from any machine
#
#   ./compose.sh start  [service ...]
#   ./compose.sh stop   [service ...]
#   ./compose.sh status [service ...]
#
# Per-run overrides: <SVC>_USER, <SVC>_HOST, <SVC>_PORT, <SVC>_REPO
# (SVC = service name with dashes removed, uppercased). Example:
#   BEDB_REPO=Capstone-Group-01/milestone4 ./compose.sh start be-db
# WAIT_SECS = how long start waits for a health check (default 60).

set -u

ACTION="${1:-}"
case "$ACTION" in
    start|stop|status) shift ;;
    *) echo "Usage: $0 start|stop|status [service ...]"; exit 1 ;;
esac

ORDER=(rabbitmq database be-db be-fe frontend)   # stop runs this in reverse
REPO="Capstone-Group-01/milestone4"              # relative to each user's $HOME
WAIT_SECS="${WAIT_SECS:-60}"

declare -A SVC_USER SVC_HOST SVC_PORT SVC_REPO SVC_START SVC_STOP SVC_CHECK

# define <name> <user> <host> <ssh-port> <repo> <start> <stop> <health-check>
define() {
    local name="$1" user="$2" host="$3" port="$4" repo="$5"
    local start="$6" stop="$7" check="$8"
    local key="${name^^}"; key="${key//-/}"
    local var
    var="${key}_USER"; SVC_USER[$name]="${!var:-$user}"
    var="${key}_HOST"; SVC_HOST[$name]="${!var:-$host}"
    var="${key}_PORT"; SVC_PORT[$name]="${!var:-$port}"
    var="${key}_REPO"; SVC_REPO[$name]="${!var:-$repo}"
    SVC_START[$name]="$start"
    SVC_STOP[$name]="$stop"
    SVC_CHECK[$name]="$check"   # runs on the target; exit 0 = up
}

define rabbitmq  jja        100.100.36.22  22    "$REPO" \
    "sudo -n systemctl start rabbitmq-server" \
    "sudo -n systemctl stop rabbitmq-server || true" \
    "systemctl is-active --quiet rabbitmq-server"

define database  it490sfc   100.123.138.94 22    "$REPO" \
    "bash scripts/start_database.sh" \
    "pkill -f '[a]mqp_co[nm]s' || true" \
    "systemctl is-active --quiet mariadb && pgrep -f '[a]mqp_co[nm]s' >/dev/null"

define be-db     pablob123  100.127.68.78  22    "$REPO" \
    "bash scripts/start_be-db.sh" \
    "pkill -x dotnet || true" \
    "pgrep -f '[d]otnet run' >/dev/null"

define be-fe     befe       100.79.8.94    2223  "$REPO" \
    "bash scripts/start_be-fe.sh" \
    "sudo -n systemctl stop cocktail-backend 2>/dev/null || true" \
    "curl -fs --max-time 2 http://localhost:5000/ >/dev/null"

define frontend  frontend   100.82.21.127  22    "$REPO" \
    "bash scripts/start_fe.sh" \
    "fuser -k 7812/tcp 2>/dev/null || true" \
    "curl -fs --max-time 2 http://localhost:7812/raw >/dev/null"

# True if $1 is an address this machine owns.
is_local() {
    tailscale ip -4 2>/dev/null | grep -qxF "$1" && return 0
    hostname -I 2>/dev/null | tr ' ' '\n' | grep -qxF "$1" && return 0
    return 1
}

# run_on <user> <host> <port> <command>: local if this machine, else SSH.
run_on() {
    local user="$1" host="$2" port="$3" body="$4"
    if is_local "$host"; then
        bash -c "$body"
    else
        ssh -n -p "$port" -o BatchMode=yes -o ConnectTimeout=5 -o StrictHostKeyChecking=accept-new \
            "$user@$host" "$body"
    fi
}

# Exit 0 if the service's health check passes. Unreachable counts as down.
is_up() {
    local n="$1"
    run_on "${SVC_USER[$n]}" "${SVC_HOST[$n]}" "${SVC_PORT[$n]}" "${SVC_CHECK[$n]}" >/dev/null 2>&1
}

print_status() {
    echo "=== Status: $1 ==="
    local n
    for n in "${TARGETS[@]}"; do
        printf '  %-10s %-16s %s\n' "$n" "${SVC_HOST[$n]}" "$(is_up "$n" && echo UP || echo DOWN)"
    done
}

start_one() {
    local n="$1"
    local u="${SVC_USER[$n]}" h="${SVC_HOST[$n]}" p="${SVC_PORT[$n]}"
    echo "--- start $n ($u@$h:$p, repo ${SVC_REPO[$n]}) ---"

    if is_up "$n"; then
        echo "    already running, skipping (run 'stop $n' first to restart)"
        return 0
    fi

    local launch
    launch=$(printf 'cd ~/%s && { nohup bash -lc %q > /tmp/%s.log 2>&1 < /dev/null & }' \
        "${SVC_REPO[$n]}" "${SVC_START[$n]}" "$n")
    if ! run_on "$u" "$h" "$p" "$launch"; then
        echo "    [!] could not launch: host unreachable or repo path missing"
        return 1
    fi

    local waited=0
    while (( waited < WAIT_SECS )); do
        if is_up "$n"; then
            echo "    [OK] health check passed after ${waited}s"
            return 0
        fi
        sleep 2
        waited=$((waited + 2))
    done

    echo "    [!] still unhealthy after ${WAIT_SECS}s. Last log lines:"
    run_on "$u" "$h" "$p" "tail -n 8 /tmp/$n.log 2>/dev/null" | sed 's/^/      /'
    return 1
}

stop_one() {
    local n="$1"
    echo "--- stop $n (${SVC_USER[$n]}@${SVC_HOST[$n]}:${SVC_PORT[$n]}) ---"
    if ! run_on "${SVC_USER[$n]}" "${SVC_HOST[$n]}" "${SVC_PORT[$n]}" "${SVC_STOP[$n]}"; then
        echo "    [!] stop failed (host unreachable?)"
        return 1
    fi
    echo "    [OK] stop command sent"
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
case "$ACTION" in
    status)
        print_status "now"
        ;;
    start)
        print_status "before"
        for t in "${TARGETS[@]}"; do start_one "$t" || FAILED+=("$t"); done
        print_status "after"
        ;;
    stop)
        print_status "before"
        for t in "${TARGETS[@]}"; do stop_one "$t" || FAILED+=("$t"); done
        sleep 2
        print_status "after"
        ;;
esac

echo
if [[ ${#FAILED[@]} -eq 0 ]]; then
    echo "Done: $ACTION (${TARGETS[*]})."
else
    echo "Failed: ${FAILED[*]}"
    exit 1
fi
