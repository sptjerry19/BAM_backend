# Docker — Backend API

Stack: **api** (nginx) + php-fpm + MySQL + Redis + Elasticsearch.

Mạng cố định: **`jb_net`** (FE join vào đây để proxy `/api`).

- Host **80/443** → để cho **frontend** (SSL: https://jbam.id.vn)
- Backend API publish mặc định **8080** (debug) + nội bộ tên service `api`
- Production `.env`: `APP_URL=https://jbam.id.vn`
- Docker DB (không dùng `root` + password trống như local XAMPP):

```env
DB_DATABASE=jbam
DB_USERNAME=jbam
DB_PASSWORD=secret
DB_ROOT_PASSWORD=rootsecret
```

## Chạy

```bash
cd backend
# .env: APP_PORT=8080
docker compose --env-file .env up -d --build
```

Rồi chạy FE (repo riêng) trên port 80 — xem `frontend/DOCKER.md`.

## Deploy EC2

```bash
bash docker/deploy-ec2.sh
```
