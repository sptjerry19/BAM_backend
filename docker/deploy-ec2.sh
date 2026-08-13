#!/usr/bin/env bash
# Deploy backend API stack on Ubuntu EC2.
# Usage:
#   cd /opt/jb-backend
#   bash docker/deploy-ec2.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "==> Checking Docker..."
if ! command -v docker >/dev/null 2>&1; then
  echo "Docker not found. Installing Docker Engine..."
  sudo apt-get update -y
  sudo apt-get install -y ca-certificates curl
  sudo install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
  sudo chmod a+r /etc/apt/keyrings/docker.gpg
  echo \
    "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
    $(. /etc/os-release && echo \"$VERSION_CODENAME\") stable" | \
    sudo tee /etc/apt/sources.list.d/docker.list >/dev/null
  sudo apt-get update -y
  sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
  sudo usermod -aG docker "$USER" || true
fi

echo "==> Ensuring swap (needed for Elasticsearch on t3.micro)..."
if ! swapon --show | grep -q .; then
  if [ ! -f /swapfile ]; then
    sudo fallocate -l 2G /swapfile || sudo dd if=/dev/zero of=/swapfile bs=1M count=2048
    sudo chmod 600 /swapfile
    sudo mkswap /swapfile
  fi
  sudo swapon /swapfile || true
  grep -q '/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
fi

echo "==> vm.max_map_count for Elasticsearch..."
sudo sysctl -w vm.max_map_count=262144
grep -q 'vm.max_map_count' /etc/sysctl.conf || echo 'vm.max_map_count=262144' | sudo tee -a /etc/sysctl.conf

if [ ! -f .env ]; then
  echo "==> Creating .env from .env.example..."
  cp .env.example .env
  if ! grep -q '^APP_KEY=base64:' .env; then
    KEY=$(docker run --rm php:8.4-cli php -r "echo 'base64:'.base64_encode(random_bytes(32));")
    sed -i "s|^APP_KEY=.*|APP_KEY=${KEY}|" .env
  fi
  if ! grep -q '^JWT_SECRET=.\+' .env || grep -q '^JWT_SECRET=$' .env; then
    JWT=$(docker run --rm php:8.4-cli php -r "echo bin2hex(random_bytes(32));")
    if grep -q '^JWT_SECRET=' .env; then
      sed -i "s|^JWT_SECRET=.*|JWT_SECRET=${JWT}|" .env
    else
      echo "JWT_SECRET=${JWT}" >> .env
    fi
  fi
  echo "Edit .env (S3, APP_URL=http://YOUR_EC2_IP) then re-run if needed."
fi

set -a
# shellcheck disable=SC1091
source .env
set +a

echo "==> Building & starting API stack..."
docker compose --env-file .env up -d --build

sleep 8
docker compose ps

IP="$(curl -s ifconfig.me 2>/dev/null || echo YOUR_EC2_IP)"
echo ""
echo "Done. Backend API only (FE is a separate repo)."
echo "  API:  http://${IP}/api"
echo "  FE:   set VITE_API_URL=http://${IP}/api when building frontend"
echo ""
echo "Useful:"
echo "  docker compose logs -f backend"
echo "  docker compose exec backend php artisan scout:import \"App\\\\Models\\\\Product\""
echo "  docker compose down"
