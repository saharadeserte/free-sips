#!/usr/bin/env bash

PORT=7812
echo "[*] Checking for existing process on port $PORT..."
PID=$(lsof -ti :$PORT)

if [ -n "$PID" ]; then
    echo "[!] Killing PID $PID currently occupying port $PORT..."
    kill -9 $PID 2>/dev/null
    sleep 1
fi

echo "[*] Launching Node.js FrontEnd on port $PORT..."
cd "$(dirname "$0")"

if [ ! -d "node_modules" ]; then
    npm install
fi

exec node server.js
