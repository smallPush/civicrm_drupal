#!/usr/bin/env bash
set -euo pipefail

docker compose --env-file .env.example config >/dev/null
docker compose --env-file .env.example build web
