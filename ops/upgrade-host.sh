#!/usr/bin/env bash
# Authorized host package update for the 2026-10-25 security maintenance.
set -euo pipefail
[[ $EUID -eq 0 ]] || { printf '%s\n' 'Run as root'; exit 1; }
systemctl is-enabled --quiet dokploy-admin-firewall.service
export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a
apt-get install -y --only-upgrade \
  'docker-ce=5:29.9.2-1~debian.12~bookworm' \
  'docker-ce-cli=5:29.9.2-1~debian.12~bookworm' \
  'containerd.io=2.4.2-3~debian.12~bookworm' \
  'docker-buildx-plugin=0.38.1-1~debian.12~bookworm' \
  'docker-compose-plugin=5.6.1-1~debian.12~bookworm' \
  'docker-ce-rootless-extras=5:29.9.2-1~debian.12~bookworm' \
  'docker-model-plugin=1.2.9-1~debian.12~bookworm'
for attempt in {1..60}; do
  if docker info >/dev/null 2>&1; then break; fi
  sleep 2
done
systemctl restart dokploy-admin-firewall.service
systemctl is-active docker containerd dokploy-admin-firewall.service
docker version --format 'client={{.Client.Version}} server={{.Server.Version}}'
docker node ls
