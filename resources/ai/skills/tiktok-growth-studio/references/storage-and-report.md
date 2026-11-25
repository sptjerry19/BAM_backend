# Thư mục chiến dịch và hợp đồng report

Đầu ra nằm trong workspace, không nằm trong thư mục cài skill. Dùng thư mục người dùng chỉ định; mặc định `social-content-starter/campaigns/YYYY-MM-DD/`. Nếu thư mục đã có một chiến dịch khác, tạo hậu tố tên ngắn hoặc tái sử dụng đúng chiến dịch khi được yêu cầu cập nhật; không ghi đè file cũ ngoài ý muốn.

## schedule/

Mỗi nội dung mới có thư mục `schedule/<content-id>/`, tên ổn định, không chứa thông tin nhạy cảm:

- Video: `final-v1.mp4`, phiên bản sau là `final-v2.mp4`.
- Ảnh đơn: `final-v1.png` hoặc định dạng phù hợp.
- Carousel: `01.png`, `02.png`… trong thư mục phiên bản, report giữ đúng thứ tự.
- `caption.txt` là caption của bản cuối; `qa.json` chứa kiểm tra kỹ thuật, kiểm tra hình/tiếng và trạng thái chờ review nếu có.

Đây là nơi giữ bản xuất cuối hoặc bản xuất chờ review; không khẳng định “đã lên lịch” chỉ vì file đã được lưu vào đây. Bản render lỗi, asset trung gian, prompt và nháp để trong `work/` khi cần. Với ảnh, mở từng ảnh để kiểm tra; helper inspect_video chỉ áp dụng video.

## reup/

Mỗi video nguồn tải được có `reup/<source-id>/source.<ext>` và `provenance.json`:

Lưu thêm `caption.txt` chứa caption tiếng Việt đề xuất cho từng video, kể cả nguồn chỉ tham khảo; ghi trạng thái chờ đủ quyền xuất bản trong report, không tự đặt lịch vì đã có caption.

- `source_url`: trang video gốc, giữ nguyên URL quan sát; không chỉ lưu CDN URL hết hạn.
- `creator`: tên/handle công khai nếu có; không bịa khi thiếu.
- `observed_at`, `downloaded_at`: thời gian có offset; `trend_evidence`: nguồn, vùng/bộ lọc và chỉ số đã thấy.
- `local_path`, `sha256`: đúng file thực tế đã tải, đã kiểm tra giải mã/phát được.
- `download_basis`: chức năng tải chính thức, file do chủ quyền cung cấp hoặc căn cứ tải khác đã xác minh.
- `rights_status`: `owned`, `licensed`, `permission_granted`, `reference_only`, `rights_unverified` hoặc `not_permitted`.
- `rights_evidence`: căn cứ và điều kiện, gồm quyền sửa, đăng TikTok, mục đích thương mại và âm nhạc nếu áp dụng. Không coi lời ghi credit là quyền sử dụng.

`reference_only`/`rights_unverified` được giữ làm tư liệu khi cách tải/lưu hợp lệ, không tự đăng lại. Nếu tải bị chặn, không tạo file source rỗng: ghi một record `download_blocked` trong report với `media_paths: []`, URL và lý do thật. Không dùng cách tải bỏ qua kiểm soát truy cập hoặc xóa watermark để né phát hiện.

## report.json

Một object gồm `campaign_id`, `generated_at`, `timezone`, `research_path` và mảng `items`.

Thêm `delivery` để đối chiếu đầu ra thực tế:

- `target`: `{ "originals": 3, "downloaded_videos": 3 }` mặc định; cập nhật nếu người dùng chỉ định khác.
- `actual`: cùng hai trường, chỉ đếm nội dung mới có bản cuối và video nguồn local đã xác minh. Bản cuối chờ review được tính là đã tạo, nhưng chưa được coi là đủ QA/được phép đăng.
- `missing`: số còn thiếu của từng nhóm; không đếm URL, file trùng, lỗi hoặc record bị chặn vào `actual`.
- `status`: `complete` khi đạt số lượng của cả hai nhóm; còn thiếu dùng `incomplete`. Đây là trạng thái giao file, tách biệt QA và lịch đăng.
- `download_attempts`: URL gốc, nền tảng, phương thức đã thử, kết quả/lý do và đường file khi tải thành công. Không lưu cookie/secret hoặc URL chứa thông tin xác thực.

Với đợt mặc định hoàn chỉnh, có 3 record `schedule` và 3 record video `reup` thực. Có thể có thêm record bị chặn, nhưng chúng không thay thế 3 file tải thật. Mỗi video nguồn có checksum riêng; không đếm cùng clip từ nhiều nền tảng hoặc các bản đổi codec thành nhiều nguồn.

Mỗi item trong `items` có:

- `id`, `bucket`: `schedule` hoặc `reup`; `media_type`: video/image/carousel.
- `publish_at`: ISO 8601 có offset khi có giờ đề xuất/lịch đã lưu; `null` nếu chưa thể đề xuất hoặc chưa được phép đăng. Với null, ghi `publish_time_reason`.
- `content`: object có `title`, `hook`, `summary`, `caption`. Với video `reup`, `caption` là caption tiếng Việt đề xuất, không để trống chỉ vì nguồn đang `reference_only`; bổ sung `caption_status` để phân biệt đề xuất chờ đủ quyền với caption đã duyệt. Ghi `original_caption` riêng khi đọc được caption gốc, nếu không dùng null; không nhận caption mình viết là lời gốc/dịch nguyên văn của tác giả. Nội dung caption phải khớp những gì thực sự đã xem, có nguồn creator, không bịa lời nói hoặc trải nghiệm.
- `src`: object có `media_paths` (danh sách file local thực, đúng thứ tự), `source_urls` (các trang gốc) và `related_source_ids` khi bản mới sử dụng nguồn trong reup. File local dùng đường dẫn tương đối từ report hoặc đường tuyệt đối, ghi nhất quán.
- `trend_classification`: verified-trend/hypothesis/evergreen; `trend_evidence`: nguồn, thời điểm quan sát và tóm tắt tín hiệu/giới hạn.
- `rights_status`, `qa_status`, `schedule_status`, `notes`.
- `platform_post_id`, `platform_post_uuid`, `planner_url`: chỉ có khi đã quan sát kết quả từ dịch vụ; nếu không có dùng null, không đoán link.

Các trường bắt buộc cho từng bài, kể cả video `reup` chỉ tham khảo (quy tắc tại [audio-and-discovery.md](audio-and-discovery.md)):

- `audio`: `current_asset_audio` (voice/music/sfx/source_audio/none/unknown), `listening_status`, `research_status`, `candidates`, `selected_track`, `selection_reason`, `voiceover_plan`, `mix_plan`, `attachment_method`, `implementation_status`, `remaining_steps`. Mỗi ứng viên/track chọn có tên, nghệ sĩ/creator, source URL/ID nếu thấy, vùng/cửa sổ nghiên cứu, observed_at, bằng chứng trend/phân loại, đoạn dùng nếu đã nghe và trạng thái quyền cho cách sử dụng. Trường chưa biết dùng null kèm lý do, không dựng tên bài. Phân biệt `embedded_in_file`, `pending_platform_attachment`, `not_selected`, `intentional_silence` và âm thanh nguồn chưa QA. Với ảnh, mô tả kế hoạch bài đăng, không nói PNG có audio.
- `caption_strategy`: câu mở thay thế, câu mở chọn, từ khóa/người xem muốn tìm, lý do chọn, CTA và trạng thái đề xuất/đã duyệt. Caption cuối vẫn nằm ở `content.caption` và `caption.txt`.
- `hashtags`: mảng tag có `tag`, `reason`, `evidence_status` và source nếu có tín hiệu trend; tag phải khớp caption cuối. Liên quan chủ đề không đồng nghĩa trend đã xác minh.

Trạng thái nên dùng nhất quán:

- `proposed`: có giờ đề xuất nhưng chưa lưu trên nền tảng.
- `awaiting_review`: media đã tạo nhưng thiếu kiểm tra/ý kiến cần thiết.
- `reference_only`: nguồn chỉ dùng nghiên cứu, không có giờ đăng.
- `download_blocked` hoặc `production_blocked`: hạng mục chưa có media vì bị chặn; ghi nguyên nhân.
- `scheduled`: readback xác nhận bài chờ đăng; giữ cả trạng thái platform như PENDING trong notes/receipt.
- `published`: có bằng chứng xuất bản; không suy từ việc giờ đăng đã qua.
- `failed`: nền tảng đã trả lỗi; không tự tạo lại khi chưa loại trừ tác dụng trước đó.

Không đưa secret, cookie hoặc URL chứa credential vào report.

## report.md

Viết tiếng Việt, liên kết `research.md` và nhóm nội dung theo schedule/reup. Mỗi mục trình bày ngắn:

Mở đầu bằng số lượng **đã tạo x/3; đã tải y/3**, `complete`/`incomplete` và phần còn thiếu. Ghi riêng tình trạng QA và lịch; giao đủ file không đồng nghĩa đã duyệt hoặc đã đăng. Với đợt đầy đủ, liên kết cả 3 nội dung mới và 3 video nguồn.

1. **Thời gian đăng:** ghi ngày, giờ Việt Nam và đây là đề xuất hay lịch đã xác nhận; nếu chưa có giờ, ghi lý do.
2. **Content:** tiêu đề, hook, tóm tắt nội dung và caption đầy đủ.
3. **Src vid/img:** liên kết mở file local; tách URL nguồn để tra cứu, không khiến người đọc nhầm URL nguồn là file có thể đưa thẳng vào Metricool.
4. Trạng thái: trend, quyền dùng, QA và lịch nền tảng; nêu phần bị chặn nếu có.
5. **Âm thanh/nhạc:** tiếng hiện có trong file, đã nghe chưa, nhạc đang xu hướng theo nguồn/vùng/thời gian nào, bài chọn và lý do, đoạn dùng nếu biết, quyền, giọng đọc/mix và đã gắn hay còn bước trong app. Nếu chưa có nhạc xác minh, nêu rõ, không bỏ mục.
6. **Caption và hashtag:** caption hoàn chỉnh, câu mở/CTA chọn vì sao, từ khóa chính, tag kèm lý do và bằng chứng nếu gọi là trend. Bổ sung thông tin này cho cả `schedule/` và `reup/`.

Chỉ tạo report thực từ kết quả lần chạy, không tạo report mẫu với media, chỉ số hoặc ID bài giả. Cuối lần chạy gửi link report và số nội dung/file thực đã tạo/tải; không chỉ dán kế hoạch vào chat.

## Kiểm tra trước khi giao

- Mọi `media_paths` đều tồn tại và không rỗng; file lỗi không được báo là thành công.
- Mọi nguồn reup thực đã tải đều có provenance, không nhân bản cùng checksum thành nhiều video mới.
- Đối chiếu `delivery` với file thực: mặc định 3 nội dung mới khác nhau và 3 video tải khác nhau. Thử nguồn thay thế khi bị chặn; nếu vẫn thiếu, giữ `incomplete` và nhật ký thử, không báo thành công 3+3.
- Giờ đề xuất có timezone; giờ đã lưu khớp readback. Nguồn chưa có quyền reup có `publish_at: null`.
- Chỉ một phiên bản cuối được chọn cho từng nội dung; report không trỏ nhầm bản nháp.
- Mọi thay đổi lịch sau đó được phản ánh vào cả JSON và Markdown.
- Mỗi record có audio/caption/hashtag với trạng thái trung thực; nhạc đề xuất không bị báo là đã gắn vào file/bài. Không gộp trạng thái giao đủ media với QA âm thanh hoặc sẵn sàng đăng. Caption và hashtag riêng khớp file cuối.
