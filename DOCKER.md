# Docker — Backend API

Stack: **api** (nginx) + php-fpm + MySQL + Redis + Elasticsearch.

Mạng cố định: **`jb_net`** (FE join vào đây để proxy `/api`).

## Mail local cho quên mật khẩu

Khởi động Mailpit riêng trên mạng Docker chung (không khởi động lại API đang chạy):

```bash
docker compose -p jbam_mail_local -f docker-compose.mail.local.yml up -d
```

Trong `.env` local dùng `MAIL_MAILER=smtp`, `MAIL_HOST=mailpit`, `MAIL_PORT=1025`,
`MAIL_FROM_ADDRESS=no-reply@jbam.local`. Mở <http://127.0.0.1:8025> để xem mã đặt lại.
Container backend cần được khởi động lại sau khi đổi `.env` để nhận biến môi trường mới.
Production phải thay `MAIL_HOST`, port, tài khoản và địa chỉ gửi bằng SMTP thực; không dùng Mailpit.

### API riêng cho source workspace

Máy đang có `landing` giữ cổng 8080 và backend của checkout `/home/jerry/server`.
Chạy source workspace trên cổng local 8082, không thay các container đó:

```bash
docker compose -p jbam_workspace_api -f docker-compose.dev-api.yml up -d
```

Frontend Vite dùng `VITE_API_URL=http://127.0.0.1:8082/api` trong `.env`.

### Build Docker local độc lập (khuyên dùng)

Stack này build source của cả `BAM_backend` và `BAM_frontend`, có MySQL, Redis và Mailpit riêng;
không kết nối network hay volume của stack `/home/jerry/server`:

```bash
docker compose -p jbam_workspace -f docker-compose.workspace.yml up -d --build
```

Frontend: <http://127.0.0.1:5173> · API: <http://127.0.0.1:8082/api> · Mailpit: <http://127.0.0.1:8026>.

- Host **80/443** → để cho **frontend** (SSL: https://jbam.id.vn)
- Backend API local publish **8082** (`APP_PORT` trong `.env`); cổng 8080 đang dành cho `landing`
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
# .env: APP_PORT=8082
docker compose --env-file .env up -d --build
```

### Dev: sửa code không cần build lại (Windows)

File `docker-compose.override.yml` (gitignored, local) mount **chọn lọc** `app/`, `routes/`, … và giữ `vendor` trên volume Linux. Không mount cả `./` — trên Docker Desktop Windows sẽ làm API chậm vài giây mỗi request.

```bash
cd backend
# Lần đầu / sau khi đổi composer.lock:
docker compose run --rm --no-deps --entrypoint composer backend install --no-interaction
docker compose up -d --force-recreate backend api
```

EC2/production: **không** dùng override — deploy vẫn `up -d --build` như cũ.

Rồi chạy FE (repo riêng) trên port 80 — xem `frontend/DOCKER.md`.

## Deploy EC2

```bash
bash docker/deploy-ec2.sh
```
