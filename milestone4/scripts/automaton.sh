#!/bin/bash
#
#
#
#   ./automaton.sh               	runs in the background (Ctrl+C to stop)
#   touch /tmp/automaton.pause          pauses it (do this before a manual stop)
#   rm /tmp/automaton.pause             resumes it

cd "$(dirname "$0")" || exit 1

INTERVAL="${INTERVAL:-15}"
MAX_RETRIES="${MAX_RETRIES:-5}"
PAUSE_FILE="/tmp/automaton.pause"
LOG_FILE="automaton.log"


TIERS=(
    "rabbitmq 1 rabbitmq rabbit2 rabbit3"
    "database 2 database database2 database3"
    "be-db    0 be-db bedb2"
    "be-fe    0 be-fe"
    "frontend 0 frontend frontend2"
)

declare -A RETRIES SEEN

# Only one at a time
exec 9>/tmp/automaton.lock
flock -n 9 || { echo "automaton is already running"; exit 1; }

log() { echo "$(date '+%F %T') $*" | tee -a "$LOG_FILE"; }


log_once() {
    local key="$1"; shift
    [[ -n "${SEEN[$key]:-}" ]] && return
    SEEN[$key]=1
    log "$*"
}
clear_once() { unset "SEEN[$1]"; }


check_node() {
    local state
    state=$(bash ./compose.sh status "$1" 2>&1 | awk -v n="$1" '$1 == n {print $NF}')
    case "$state" in
        UP)   return 0 ;;
        DOWN) return 1 ;;
        *)    return 2 ;;
    esac
}

log "automaton started (status mode, interval ${INTERVAL}s, max retries ${MAX_RETRIES})"

while true; do
    if [[ -e "$PAUSE_FILE" ]]; then
        sleep "$INTERVAL"
        continue
    fi

    blocked_by=""

    for tier in "${TIERS[@]}"; do
        read -r name min_up nodes <<< "$tier"
        up=(); down=()
        total=0

        for n in $nodes; do
            total=$((total + 1))
            check_node "$n"
            case $? in
                0)
                    up+=("$n")
                    RETRIES[$n]=0
                    clear_once "giveup:$n"
                    clear_once "config:$n"
                    ;;
                1)
                    down+=("$n")
                    ;;
                *)
                    log_once "config:$n" "CONFIG ERROR: $n can't be checked (run './compose.sh status $n' to see why). Skipping it."
                    ;;
            esac
        done

        if (( ${#down[@]} == 0 )); then
            clear_once "crit:$name"
            clear_once "blocked:$name"
            continue
        fi


        if (( min_up > 0 && ${#up[@]} < min_up )); then
            log_once "crit:$name" "CRITICAL: $name has ${#up[@]}/$total node(s) up (needs $min_up). Not auto-restarting; manual recovery needed."
            blocked_by="${blocked_by:-$name}"
            continue
        fi
        clear_once "crit:$name"


        if [[ -n "$blocked_by" ]]; then
            log_once "blocked:$name" "$name has down node(s) (${down[*]}) but $blocked_by is critical. Waiting for $blocked_by to recover."
            continue
        fi
        clear_once "blocked:$name"

        for n in "${down[@]}"; do
            tries="${RETRIES[$n]:-0}"
            if (( tries >= MAX_RETRIES )); then
                log_once "giveup:$n" "ALERT: $n still down after $MAX_RETRIES restart attempts (host may be unreachable, or the service keeps failing; see /tmp/$n.log on the node). Giving up until it comes back on its own."
                continue
            fi
            RETRIES[$n]=$((tries + 1))
            log "$name: $n is down (${#up[@]}/$total up), restarting (attempt ${RETRIES[$n]}/$MAX_RETRIES)"
	if bash ./compose.sh start "$n" >> "$LOG_FILE" 2>&1; then
		log "$name: $n RECOVERED (restart succeeded)"
else
		log "$name: $n restart FAILED (attempt ${RETRIES[$n]}/$MAX_RETRIES), will retry"
fi
         done
    done

    sleep "$INTERVAL"
done
