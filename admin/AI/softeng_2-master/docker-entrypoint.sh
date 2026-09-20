#!/bin/bash
set -e

# Ensure SQLite database exists
if [ ! -f "prisma/dev.db" ]; then
  echo "[entrypoint] Initializing SQLite database schema..."
  npx prisma db push --skip-generate || true
fi

# Start the Python ML prediction service in the background
echo "[entrypoint] Starting Flask ML server (server.py) on port 5000..."
python3 server/server.py &
PY_PID=$!

# Wait briefly for ML server to spin up
sleep 1

# Start the Next.js web application
echo "[entrypoint] Starting Next.js web application on port 3000..."
npm start &
NODE_PID=$!

# Function to handle shutdown signals
cleanup() {
  echo "[entrypoint] Stopping services..."
  kill -TERM "$PY_PID" "$NODE_PID" 2>/dev/null || true
  wait "$PY_PID" "$NODE_PID" 2>/dev/null || true
  exit 0
}

trap cleanup INT TERM

# Wait for either process to terminate
wait -n "$PY_PID" "$NODE_PID" 2>/dev/null || wait "$PY_PID" "$NODE_PID" || true
cleanup

