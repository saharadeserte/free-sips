#!/usr/bin/env bash

SERVICE_NAME="BE-DB C# Worker"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "[*] Checking for existing instances of $SERVICE_NAME..."

# Terminate existing dotnet worker instances
PIDS=$(pgrep -f "dotnet run|dotnet.*be" || true)
if [ -n "$PIDS" ]; then
    echo "[!] Terminating existing process(es): $PIDS..."
    #pkill -f "dotnet" 2>/dev/null || true
    sleep 1
fi

echo "[*] Launching C# Database-Backend service from $SCRIPT_DIR..."
cd "$SCRIPT_DIR"

# Ensure .NET SDK is available
if ! command -v dotnet &> /dev/null; then
    echo "[-] Error: .NET SDK ('dotnet') not found in PATH."
    exit 1
fi

# Execute the C# project
#exec dotnet run
nohup dotnet run --no-launch-profile \
    > /tmp/be-db.log 2>&1 < /dev/null &