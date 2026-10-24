#!/usr/bin/env bash
set -e

PORT=7812
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo "[*] Checking for existing process on port $PORT..."
PID=$(lsof -ti :$PORT || true)
if [ -n "$PID" ]; then
    echo "[!] Killing PID $PID occupying port $PORT..."
    kill -9 $PID 2>/dev/null || true
    sleep 1
fi

# Ensure node_modules exists (suppress noisy npm outputs)
if [ ! -d "node_modules" ]; then
    echo "[*] Installing dependencies..."
    npm install --no-audit --no-fund
fi

echo "[*] Booting Node.js FrontEnd on port $PORT..."
node server.js &
NODE_PID=$!

# Wait and poll for port readiness (up to 5 seconds)
MAX_RETRIES=10
COUNT=0
ONLINE=0

while [ $COUNT -lt $MAX_RETRIES ]; do
    if lsof -ti :$PORT >/dev/null 2>&1; then
        ONLINE=1
        break
    fi
    # Check if the node process died unexpectedly
    if ! kill -0 $NODE_PID 2>/dev/null; then
        echo "[-] Error: Node.js process crashed immediately upon startup."
        wait $NODE_PID || true
        exit 1
    fi
    sleep 0.5
    COUNT=$((COUNT + 1))
done

if [ $ONLINE -eq 1 ]; then
    echo "[+] FrontEnd service is verified ONLINE on http://0.0.0.0:$PORT (PID $NODE_PID)"
    # Keep the script attached to the Node process so Composer stays running
    wait $NODE_PID
else
    echo "[-] Error: Timed out waiting for port $PORT to open."
    kill -9 $NODE_PID 2>/dev/null || true
    exit 1
fi


