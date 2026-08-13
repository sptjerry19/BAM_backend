# Docker — Backend API

Stack: nginx + php-fpm + MySQL + Redis + Elasticsearch.

Dùng **một file `.env`** (không còn `.env.docker`).  
Trong container, compose **ghi đè** `DB_HOST=mysql`, `REDIS_HOST=redis`, `ELASTICSEARCH_HOST=...` nên `.env` local vẫn có thể để `DB_HOST=127.0.0.1` khi chạy `php artisan serve`.

FE repo riêng: `VITE_API_URL=http://API_HOST/api`.

## Chạy local

### Windows (PowerShell)

```powershell
cd backend
.\docker\up.ps1
```

```powershell
docker compose --env-file .env up -d --build
```

### Linux / macOS / EC2

```bash
cd backend
bash docker/up.sh
# hoặc
bash docker/deploy-ec2.sh
```

API: http://localhost/api

## Biến Docker thêm trong `.env` (tuỳ chọn)

```env
DB_ROOT_PASSWORD=rootsecret
RUN_SCOUT_IMPORT=true
APP_PORT=80
ES_JAVA_OPTS=-Xms256m -Xmx256m
```

## Lệnh hữu ích

```bash
docker compose --env-file .env logs -f backend
docker compose --env-file .env exec backend php artisan scout:import "App\\Models\\Product"
docker compose --env-file .env down
```
