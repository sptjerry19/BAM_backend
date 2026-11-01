# Trang cá nhân và WebSocket

## Chức năng và đường dẫn

- `/profile`: trang của mình; `/users/:userId`: hồ sơ người khác; `/athletes/:memberId`: ánh xạ hồ sơ thể thao, không coi member ID là user ID.
- Tab Trang bị dùng nguồn sản phẩm/trang bị tự nhập hiện có. Quản lý vẫn tại `/equipment`; khách chỉ đọc phần được công khai, không thấy giá mua/ghi chú.
- Bài viết, ảnh/video, cảm xúc, bình luận/trả lời, lưu/chia sẻ, ghim, quyền xem; bạn bè/follow/block/report; thông báo; album, duyệt gắn thẻ, lưu trữ/thùng rác; tin 24 giờ và nổi bật; chat riêng có tin nhắn chờ, ảnh và trạng thái đọc.
- `/messages`, `/notifications`, `/settings/account`; admin xem `/admin/social-reports`.

## Cài đặt

Trong backend chạy `composer install` và `php artisan migrate`. Migration bổ sung bảng, backfill user_profiles, không biến bài/ảnh mẫu cũ thành nội dung thật. Backup database trước khi triển khai lên máy có dữ liệu quan trọng.

Trong frontend chạy `npm ci`. Thêm cấu hình backend sau vào `.env` (không commit secrets):

```dotenv
APP_URL=https://ten-mien-cua-ban
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=redis
CACHE_DRIVER=redis
REVERB_APP_ID=jbadminton
REVERB_APP_KEY=<chuoi-ngau-nhien-cong-khai>
REVERB_APP_SECRET=<chuoi-ngau-nhien-bi-mat>
REVERB_HOST=reverb
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_ALLOWED_ORIGINS=ten-mien-cua-ban,www.ten-mien-cua-ban
REDIS_QUEUE_RETRY_AFTER=660
```

APP_URL phải là URL public đúng scheme. Tạo KEY/SECRET riêng bằng `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` cho mỗi giá trị; chỉ app key được đưa vào frontend. Origin là hostname, không bao gồm scheme/path. Local thêm localhost/127.0.0.1.

Trong frontend `.env` hoặc môi trường Compose build:

```dotenv
VITE_API_URL=/api
VITE_REVERB_APP_KEY=<giong-REVERB_APP_KEY>
VITE_REVERB_HOST=ten-mien-cua-ban
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

VITE_* là biến lúc build; rebuild frontend sau khi đổi. Khi dùng Vite ngoài Docker, đặt VITE_API_URL trỏ tới backend, VITE_REVERB_PORT=8081 và scheme=http cho local override bên dưới.

## Docker

Trong backend, production dùng:

```sh
docker compose -f docker-compose.yml -f docker-compose.social.yml build
docker compose -f docker-compose.yml -f docker-compose.social.yml up -d mysql redis elasticsearch backend api
docker compose -f docker-compose.yml -f docker-compose.social.yml exec backend php artisan migrate --force
docker compose -f docker-compose.yml -f docker-compose.social.yml up -d reverb queue scheduler media-worker
```

Không thêm local override ở production. Nếu quy trình deploy đã chạy migration riêng, đặt SKIP_ENTRYPOINT_MIGRATE=true để chỉ chạy migration một lần.

Local dùng cùng bộ file khi up/exec/restart:

```sh
# Bắt buộc lần đầu / mỗi khi composer.lock thay đổi: image mới không ghi đè vendor volume cũ.
docker compose run --rm --no-deps --entrypoint composer backend install --no-interaction --prefer-dist --no-scripts
docker compose run --rm --no-deps --entrypoint php backend artisan package:discover
docker compose -f docker-compose.yml -f docker-compose.override.yml -f docker-compose.social.yml -f docker-compose.social.local.yml up -d --build
```

Nếu gặp `Class "Laravel\Reverb\ReverbServiceProvider" not found`, dừng backend bằng `docker compose stop backend`, chạy hai lệnh cập nhật dependency ở trên rồi khởi động lại. Không xóa volume database hay chạy `down -v` để xử lý lỗi vendor.

File cuối đồng bộ source/vendor cho API và các process phụ, đồng thời publish Reverb chỉ trên `127.0.0.1:8081`. Sau khi thay composer.lock, cập nhật backend_vendor theo DOCKER.md trước khi restart các process.

Frontend chạy `docker compose -f docker-compose.yml up -d --build` cho HTTPS hoặc `docker compose -f docker-compose.http.yml up -d --build` cho local HTTP. Nginx proxy `/app/` tới reverb:8080 trên jb_net. HTTP publisher `/apps/` không được proxy ra ngoài. Upload video tối đa 50 MB; cần bảo đảm giới hạn PHP và Nginx tương ứng.

Các process:

Backend healthcheck `social:health` chờ schema cuối cùng, Redis và cấu hình Reverb; các process phụ chỉ khởi động sau khi backend healthy. Nếu chúng không lên, kiểm tra migration và REVERB_APP_KEY/SECRET trước.

- reverb: kết nối WebSocket lâu dài;
- queue: Redis queue broadcasts/default;
- scheduler: đưa outbox chưa phát vào queue mỗi giây, dọn thùng rác/upload mồ côi và phục hồi trạng thái video timeout;
- media-worker: queue media riêng, FFmpeg/ffprobe trong image, timeout 600 giây thấp hơn retry_after 660;
- redis: AOF/volume riêng; MySQL lưu tin nhắn/outbox nên socket/Redis lỗi không làm mất dữ liệu đã commit.

Khi deploy code mới, restart/recreate cả worker, scheduler và Reverb. Mặc định một Reverb; chỉ bật scaling khi có nhiều instance nối cùng Redis. Private media hiện lưu trên volume backend_storage, không qua public/storage; muốn chạy backend trên nhiều host cần triển khai storage private dùng chung trước.

## Luồng tin nhắn

POST HTTP kiểm quyền và lưu message cùng outbox trong transaction. Scheduler/queue phát sự kiện tối thiểu vào private user channel qua Reverb. Client dùng ID sự kiện để đồng bộ HTTP, chống trùng, tải bù theo cursor sau reconnect; không long polling. Mỗi lần gửi có client_message_id để retry không tạo tin trùng. File ảnh không truyền qua WebSocket.

JWT bảo vệ `/api/broadcasting/auth`; channel chỉ của chính user. JWT refresh/reconnect được xử lý ở frontend; nội dung đầy đủ luôn tải qua API kiểm quyền hiện thời, socket không mang body tin nhắn. Hội thoại chỉ có một bản ghi/cặp user. Người lạ gửi một tin đầu tiên rồi chờ chấp nhận; block ngăn tin mới, vẫn cho hai bên xem lịch sử cũ.

Media dùng URL ký có hạn một phút; mỗi lần đọc vẫn kiểm lại quyền nội dung/album/quan hệ hiện tại. Component media xin URL mới khi URL hết hạn. Tin hết hạn bị lọc trực tiếp ở truy vấn, không đợi scheduler. Tin được đưa vào nổi bật vẫn chịu quyền tin gốc.

Ảnh trang bị tự nhập mới từ upload base64 lưu private. Ảnh cũ đã nằm trên public disk/S3 public hoặc nguồn bên ngoài vẫn có thể truy cập nếu người khác đã biết URL gốc; không thể thu hồi URL của bên thứ ba. Trước khi chuyển dữ liệu cũ nhạy cảm sang riêng tư, di chuyển ảnh do hệ thống sở hữu và thu hồi đường dẫn public theo quy trình storage hiện tại.

## Kiểm tra

```sh
php artisan test --filter=ProfileSocialTest
php artisan test
npm run build
docker compose -f docker-compose.yml -f docker-compose.social.yml config --quiet
```

`scripts/social-smoke-setup.php` tạo database SQLite riêng trong storage/framework/testing cùng ba tài khoản kiểm thử; `scripts/social-smoke-tokens.php` chỉ làm mới JWT kiểm thử. Không chạy seed kiểm thử vào database ứng dụng. Smoke script frontend `scripts/social-websocket-smoke.mjs` kiểm tra handshake, private JWT, gửi tin qua API/outbox/Reverb, đọc lịch sử, retry chống trùng và tài khoản thứ ba bị chặn. Script yêu cầu Node 24+; API mặc định 127.0.0.1:18090, Reverb 127.0.0.1:8091, test app key social-smoke-key; chỉ dùng các credential mẫu này trên localhost kiểm thử.

Hạ tầng production, TLS qua tên miền thật, tải đồng thời và kiểm thử UI trực tiếp cần được xác minh trên môi trường triển khai. Chưa bổ sung trạng thái online/đang nhập; đây là phần mở rộng sau luồng chat cơ bản trong plan.

Đã kiểm chứng ở môi trường local: build Vue; toàn bộ bộ test backend; smoke HTTP → outbox → Reverb → nhận qua WebSocket; image Docker build được; private subscription/publish/receive trong container; job ProcessVideo thật với SQLite trong bộ nhớ, transcode và tạo poster. Script video là `scripts/social-video-smoke.php` (chạy trong image có FFmpeg); script WebSocket container là `frontend/scripts/social-docker-websocket-smoke.mjs`, dùng duy nhất credential mẫu trên localhost. Chưa có xác nhận QA bằng trình duyệt vì browser automation host không truy cập được và native app approval hết thời gian chờ.

Kết quả kiểm thử hiện tại: 68 tests backend / 448 assertions đều qua; Vue production build qua. Chưa chạy migration trên database ứng dụng hiện có và chưa triển khai production.
