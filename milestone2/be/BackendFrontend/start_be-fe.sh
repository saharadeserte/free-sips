#!/bin/bash

set -e

echo "--- Starting Backend-FrontEnd ---"

SERVICE="cocktail-backend"
URL="http://localhost:5000/"

# Check if backend is already running
if systemctl is-active --quiet "$SERVICE"; then
    echo "[+] CocktailBackend is already running through systemd."
else
    echo "[+] Starting CocktailBackend through systemd..."

    sudo -n systemctl start "$SERVICE"
fi

# Verify service is running
if ! systemctl is-active --quiet "$SERVICE"; then
    echo "[!] Error: CocktailBackend failed to start."
    exit 1
fi

# Verify HTTP endpoint responds successfully
echo "[+] Checking backend HTTP endpoint..."

for i in {1..15}; do
    if curl --silent --fail --max-time 2 "$URL" > /dev/null; then
        echo "[+] CocktailBackend started successfully!"
        echo "[+] Service: $SERVICE"
        echo "[+] URL: $URL"
        exit 0
    fi
    sleep 2
done

echo "[!] Error: Backend did not respond on port 5000."
exit 1
