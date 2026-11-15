#!/usr/bin/env bash

SERVICE_NAME="BE-DB C# Worker"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "$SCRIPT_DIR/../BackendDatabase" && pwd)"

echo "[*] Checking for existing instances of $SERVICE_NAME..."

# Terminate existing dotnet worker instances without killing the SSH session
if pgrep -x "dotnet" > /dev/null; then
    echo "[!] Terminating existing dotnet process(es)..."
    pkill -x "dotnet" 2>/dev/null || true
    sleep 1
fi

echo "[*] Launching C# Database-Backend service from $APP_DIR..."
cd "$APP_DIR" || { echo "[-] Error: Directory $APP_DIR not found."; exit 1; }

# Ensure .NET SDK is available
if ! command -v dotnet &> /dev/null; then
    echo "[-] Error: .NET SDK ('dotnet') not found in PATH."
    exit 1
fi

# Execute the C# project
ASPNETCORE_ENVIRONMENT=Development dotnet run --no-launch-profile
