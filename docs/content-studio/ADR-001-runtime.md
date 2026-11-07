# ADR-001: Runtime và topology cho JBAM AI Content Studio

- **Trạng thái:** Accepted
- **Ngày:** 2026-11-07
- **Phạm vi:** module Content Studio trong `BAM_backend` (repo này) và trang admin trong `frontend`
- **Tài liệu gốc:** `JBAM_AI_Content_Studio_Implementation_Plan.md` v1.2 (thư mục workspace cha)

## Bối cảnh

Content Studio tự động lập kế hoạch, sản xuất (carousel, video), kiểm duyệt, lên lịch và đo lường nội dung TikTok `@jerrybadminton`. Plan v1.0 viết khi chưa biết runtime thật, nên đưa ra hai phương án (Node/Postgres trên Vercel hoặc Laravel). Audit codebase và xác nhận của chủ kênh ngày 2026-11-07 cho kết quả dưới đây.

### Runtime hiện tại

| Hạng mục | Thực tế |
|---|---|
| Repo | `github.com/sptjerry19/BAM_backend`, branch chính `main` |
| Framework | Laravel 13.24 (PHP ^8.3), bootstrap kiểu cũ: `App\Console\Kernel`, `App\Http\Kernel` |
| DB | MySQL 8.4 (container `mysql`, bind `127.0.0.1:3306`) |
| Queue / cache | Redis 7 (`appendonly yes`), `QUEUE_CONNECTION=redis`, `retry_after=660` |
| Storage | S3 API: MinIO trong compose (public qua nginx `/s3/{bucket}`); LocalStack khi dev |
| Auth | JWT `auth:api` + middleware `admin` (`users.role = 'admin'`) |
| Máy chủ | **Laptop Ubuntu khoảng 16GB RAM**, chạy Docker Compose; suspend đã tắt |
| Compose đang chạy | 4 file: `docker-compose.yml` + `docker-compose.override.yml` + `docker-compose.social.yml` + `docker-compose.social.local.yml`, cộng frontend. Vì vậy `queue`, `media-worker`, `scheduler` (`schedule:work`, scope `all`) và `reverb` **đang chạy**, lịch `social:*` đang hoạt động. Mọi container bind-mount `app/ config/ routes/ database/` từ `backend/`. *(Bản đầu của ADR ghi "chỉ BE + FE"; đã đính chính ngày 2026-11-07 bằng `docker ps` / `docker inspect`.)* |
| Domain | `jbam.id.vn` đi qua Cloudflare về laptop; cấu hình Cloudflare nằm ngoài repo |
| Backup | MySQL đã được backup trước khi bắt đầu Content Studio |

`docker/deploy-ec2.sh` và các ghi chú về EC2 khoảng 1GB trong repo **không** phản ánh cách deploy hiện tại.

## Quyết định

1. **Backend duy nhất là Laravel trong repo này.** Không tạo service Node / Python có API riêng.
2. **Topology một máy.** Thêm overlay `docker-compose.content.yml` với ba container dùng chung image / mạng `jb_net`:
   - `content-scheduler`: `schedule:work` với `CONTENT_SCHEDULE_SCOPE=content`;
   - `content-queue`: `queue:work redis --queue=content --timeout=180` (AI, plan, QC, publisher, sync);
   - `content-media`: `queue:work redis-media --queue=content-media --timeout=1800`; image mở rộng thêm Python, FFmpeg, faster-whisper, TTS, Playwright; giới hạn CPU / RAM; concurrency 1.
3. **Không làm đổi hành vi social.** `Kernel::schedule()` đọc `content-studio.schedule_scope`: `content` chỉ đăng ký lịch `content:*`; mặc định `all` giữ nguyên hành vi hiện có (scheduler cũ vẫn chạy `social:*`). Không sửa `docker-compose.social*.yml`. Vì scheduler cũ (scope `all`) và `content-scheduler` dùng chung `.env`, mọi entry content dùng `onOneServer()` (khóa Redis) để không chạy hai lần khi bật `CONTENT_STUDIO_ENABLED`.
4. **Durability bằng MySQL.** Redis chỉ chuyển job. Mỗi job tương ứng một `content_pipeline_steps` (lease, attempt, output). `StepRunner` đảm bảo chạy lại an toàn; `content:recover` (mỗi 15 phút và `--boot` khi container khởi động) dựng lại tiến trình sau khi mất message hoặc laptop tắt. Không chạy bù lịch đã lỡ, không backdate giờ đăng.
5. **Kết nối Redis riêng cho media.** Thêm `redis-media` trong `config/queue.php` (cùng Redis, `retry_after` lớn hơn timeout 1800s) để job video dài không bị chạy hai lần.
6. **Migration chỉ tạo bảng mới `content_*`** bằng Schema Builder; không ALTER bảng booking / member / social / products.
7. **Admin API** ở `routes/content-studio.php` (require từ `routes/api.php`), middleware `auth:api` + `admin`. Container content không mở port.
8. **Feature flags mặc định tắt:** `CONTENT_STUDIO_ENABLED=false`, `CONTENT_AUTOPUBLISH_ENABLED=false`.
9. **Nội dung:** thêm slot `equipment` (dữ liệu bảng `products`); kho reup theo skill `tiktok-growth-studio`: tải bằng phương thức được phép kèm provenance, mặc định `reference_only` / `rights_unverified`, **không bao giờ** lên lịch khi chưa có bằng chứng quyền.

## Phương án đã loại

| Phương án | Lý do loại |
|---|---|
| Node/Express + Postgres (Option A v1.0) | Không phải runtime thật |
| Laptop worker gọi API pull (v1.1) | Chỉ cần khi worker ở máy khác; server và worker cùng laptop |
| Bảng `content_jobs` + `SKIP LOCKED` riêng | Trùng chức năng Laravel queue; độ bền đã có nhờ `content_pipeline_steps` |
| Dùng luôn `scheduler` / `queue` của stack social cho content | Queue `broadcasts,default` có `--timeout=60` (< OpenAI 90s), `media` đang phục vụ video social; trộn sẽ ảnh hưởng nhau. Dùng service riêng |
| Render carousel bằng GD làm chính | Đủ RAM để dùng Playwright, chữ tiếng Việt đẹp và dễ thiết kế hơn; GD giữ làm fallback |

## Hệ quả

- Laptop tắt / mất điện / mất mạng ⇒ cả website lẫn Content Studio dừng. Bài đã gửi sang Metricool vẫn đăng theo lịch phía Metricool.
- Lệnh chạy chuẩn: `docker compose -f docker-compose.yml -f docker-compose.override.yml -f docker-compose.social.yml -f docker-compose.social.local.yml -f docker-compose.content.yml up -d --build --no-deps --no-recreate content-scheduler content-queue`. **Phải đủ 5 file**: chỉ dùng 2 file thì compose tính lại `backend`/`api` thiếu override + social và tạo lại chúng sai cấu hình. Container content không bind-mount source (chạy code đã build), nên cần `up -d --build` (bỏ `--no-recreate`) sau khi đổi code.
- URL media công khai cho Metricool / TikTok đi qua Cloudflare → nginx `/s3/{bucket}`. Cần kiểm tra ở Phase 4 (cache, giới hạn kích thước file, verify domain với TikTok).
- `content-media` dùng chung CPU / RAM với website; giới hạn tài nguyên trong compose và đo `docker stats` ở Phase 3.

## Việc còn mở

- [ ] Benchmark laptop (CPU / GPU / disk) trước khi chọn model Whisper (Phase 3).
- [ ] OpenAI API key + ngân sách (Phase 2).
- [ ] Metricool API access (gói Advanced / Custom) hoặc chấp nhận manual export (Phase 4).
- [ ] Kiểm tra Cloudflare không chặn / cache sai media dùng cho publisher (Phase 4).
