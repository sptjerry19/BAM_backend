# Run from backend/:  .\docker\up.ps1
$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
  Write-Error "Docker CLI not found. Install/start Docker Desktop first."
}

try {
  docker info 2>$null | Out-Null
  if ($LASTEXITCODE -ne 0) { throw "docker info failed" }
} catch {
  Write-Host "Docker Desktop engine is not ready (causes: unable to get image ... 500)."
  Write-Host "Fix: open Docker Desktop, wait until Running, or Restart from tray, then re-run."
  exit 1
}

if (-not (Test-Path .env)) {
  Copy-Item .env.example .env
  Write-Host "Created .env from .env.example - set APP_KEY / JWT_SECRET / S3 as needed."
}

docker compose --env-file .env up -d --build @args
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
docker compose --env-file .env ps
