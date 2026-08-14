#!/usr/bin/env bash
set -euo pipefail

if docker compose version >/dev/null 2>&1; then
  COMPOSE_CMD="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE_CMD="docker-compose"
else
  echo "Neither 'docker compose' nor 'docker-compose' found" >&2
  exit 1
fi

$COMPOSE_CMD --env-file .env.example config >/dev/null
$COMPOSE_CMD --env-file .env.example build web
