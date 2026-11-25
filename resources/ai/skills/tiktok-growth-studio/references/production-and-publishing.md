# Sản xuất, kiểm tra và lên lịch

## Gói làm việc cho mỗi video

Tạo ở thư mục đầu ra riêng của chiến dịch, ngoài thư mục skill. Tuân theo `schedule/`, `reup/` và report trong [storage-and-report.md](storage-and-report.md); không tiếp tục để bản cuối rải ở gốc chiến dịch. Chỉ tạo các file có ích cho lần chạy:

- Brief: đối tượng, insight, bằng chứng trend, hook và shot list có diễn biến/payoff.
- Asset log: file/URL, chủ quyền, loại quyền/giấy phép và bằng chứng/điều kiện liên quan cho từng ảnh, footage, giọng, nhạc; ghi rõ asset tự tạo.
- Bản cuối: MP4 hoặc định dạng nền tảng hỗ trợ, caption và ghi chú nhãn AI/thương mại.
- Biên nhận sau lên lịch: ID/UUID, tài khoản, thời gian có timezone, media đã dùng, trạng thái, Auto publish, thời điểm readback và link planner nếu tool trả.
- Report JSON và Markdown: thời gian đăng, content, src vid/img cho mỗi bản tạo mới và mỗi nguồn tải về; không thay bằng một biên nhận kỹ thuật đơn lẻ.

Không cần lộ email chủ tài khoản hoặc lưu token vào gói. Không coi file prompt là bằng chứng sở hữu footage của bên khác.

## Các tuyến sản xuất

**Clip thật có quyền:** chọn khoảnh khắc có hành động/câu chuyện; cắt bỏ thời gian chờ, dựng lời bình/phụ đề, thêm freeze frame hoặc zoom chỉ khi giúp giải thích. Bảo toàn bối cảnh; không ghép thành sự kiện/người nói điều họ không làm.

**Không có clip mới:** thiết kế scene có ý nghĩa bằng ảnh/hoạt họa tự tạo hoặc footage đã được cấp phép. Tạo các shot riêng với chủ thể/đạo cụ nhất quán; chuyển cảnh theo câu chuyện. Nếu thiếu năng lực tạo chuyển động/diễn xuất, chọn định dạng ảnh carousel tốt thay vì gọi một slideshow sơ sài là video đã đạt.

**Video AI:** dùng công cụ đã kết nối, kiểm tra credit và giới hạn đầu ra trước khi gọi. Không tự chuyển nhà cung cấp trả phí khi job lỗi. Giới hạn số lần sửa có chủ đích theo ngân sách, tránh lặp vô hạn. Với cảnh cầu lông, xem kỹ tay, vợt, lưới, quỹ đạo và tính nhất quán; không trình bày cảnh AI như clip thi đấu thật.

**Tải và tái sử dụng video trend:** dùng phương thức tải được phép và lưu bản nguồn trong `reup/` cùng provenance; nếu chưa có quyền đăng, đánh dấu chỉ tham khảo. Chỉ dùng asset vào video xuất bản nếu căn cứ quyền bao phủ nền tảng, mục đích thương mại nếu có, chỉnh sửa và âm nhạc. Chưa rõ quyền thì dựng ý tưởng riêng bằng asset hợp lệ. Không gỡ watermark hay biến đổi để né phát hiện.

## Kiểm tra chất lượng

Trước dựng, chốt phương án nhạc/giọng/SFX theo [audio-and-discovery.md](audio-and-discovery.md); report phải ghi tiếng thực có, nhạc trend đã xác minh hay chưa và bước gắn nhạc còn thiếu. Với ảnh/carousel, ghi kế hoạch sound trên nền tảng, không coi file ảnh là bài đã có âm thanh.

Xem hết bản cuối và nghe hết tiếng, kiểm tra ở kích thước hiển thị điện thoại:

- Mở đầu cho thấy tình huống/giá trị ngay, thay vì một màn hình logo hoặc đoạn giải thích dài.
- Hình và tiếng thay đổi theo diễn biến; payoff đáp ứng điều hook hứa. Không dùng nhiều hiệu ứng để che nội dung thiếu ý.
- Chữ dễ đọc, ngắn đủ theo dõi và tránh phần giao diện TikTok/thumbnail che; không có lỗi dấu tiếng Việt hoặc chữ AI sai.
- Tiếng rõ, không rè/clipping; nếu cố ý không tiếng, người xem vẫn hiểu câu chuyện.
- Không có watermark của bên khác, hình sai sản phẩm, trải nghiệm giả hoặc asset chưa rõ quyền.
- Mỗi video có một mục tiêu/CTA phù hợp. Không dùng ép tương tác hoặc lời hứa phần sau để giấu toàn bộ đáp án.

Đây là quyết định biên tập, không thể thay bằng điểm do chính model tự chấm hoặc kết quả ffprobe. Nếu môi trường không phát/nghe được toàn bộ video, nói rõ chưa hoàn tất QA và dùng công cụ xem phù hợp hoặc nhờ người dùng xem bản cụ thể trước khi tự đăng.

Kiểm tra kỹ thuật bằng helper:

```powershell
python scripts/inspect_video.py 'D:/path/final.mp4' --ffprobe 'D:/path/ffprobe.exe' --ffmpeg 'D:/path/ffmpeg.exe'
```

Đường `scripts/` tính từ thư mục skill; thay đường dẫn thực tế. Script chỉ đọc media, xuất JSON và không tải lên/đăng bài. Nó kiểm tra decode, container/codec, độ phân giải, tỉ lệ, fps và checksum. Cảnh báo audio/độ dài cần quyết định biên tập; không mặc định track audio tồn tại nghĩa là có tiếng.

## Metricool

1. Khám phá schema tool hiện có; đọc brand settings và lịch trong khoảng dự kiến. Đối chiếu lại quyền của yêu cầu hiện tại, tránh bài trùng và lịch chồng không chủ đích.
2. Lấy giờ gợi ý đúng network/múi giờ nếu người dùng chưa chọn, trong cửa sổ tối đa một tuần. Chọn lịch tương lai theo thời gian client; kiểm tra hạn mức còn lại bằng khả năng thực tế của tài khoản, không đóng cứng gói Free trong skill.
3. Tải bản cuối đến đích được phép. MCP nhận media mà dịch vụ đọc được, thường là URL trực tiếp; `D:/...` chỉ là file local. Có thể dùng đường asset đã tải vào Metricool, không lộ credential trong URL. Tải media lên không đồng nghĩa đã lên lịch.
4. Tạo bài theo schema chính thức hiện tại; kiểm tra privacy, Auto publish và các nhãn phù hợp. Không cố dùng API token trả phí để vượt giới hạn gói miễn phí.
5. Nếu timeout/kết quả không rõ: đọc lại lịch. Nếu có đúng một bài khớp, tiếp tục với ID/UUID đó; nếu nhiều bài trùng hoặc không xác định được, báo sự không chắc chắn và dừng tạo thêm, không tự xóa bài.
6. Sau thành công: đọc lại bài, lưu biên nhận và cập nhật giờ/trạng thái/src trong report. `PENDING` nghĩa là chờ đăng, không phải đã xuất bản. Không tạo một bản nữa chỉ để lấy link planner.

Nếu API/plugin không có upload và kết nối hosting không có quyền, thử phương án phù hợp đã được cho phép; thiếu quyền không phải lý do tự mở rộng mọi scope hoặc đưa file riêng tư lên host công khai bất kỳ.

## Tác vụ định kỳ

Để tự động hằng tuần cần scheduler thật, nguồn asset, model/công cụ có thể gọi trong môi trường đó, hosting, xác thực và giới hạn chi phí. Kiểm tra từng năng lực; không giả định scheduler có thể dùng một phiên MCP/OAuth của chat hoặc image generation trong chat như API miễn phí.

Trước khi kích hoạt, xác định rõ tài khoản, lịch, timezone, số bài/tần suất tối đa, ngân sách, quyền dùng asset, cách dừng và xử lý lỗi. Lưu khóa nhận dạng job/bài để tránh chạy lại tạo trùng. Chỉ tuyên bố đã kích hoạt sau khi đọc lại tác vụ thành công; thông báo phần nào vẫn cần người dùng.

## Đo kết quả

Đọc danh sách metric hiện có trước khi yêu cầu field cụ thể. Ghi thời điểm và tuổi bài lúc đo; dùng 24h/72h như các mốc vận hành có thể điều chỉnh. Phân biệt thiếu dữ liệu với 0. Nếu không có retention, nêu rõ và chỉ dùng chỉ số thực có. Mỗi vòng đề xuất thay đổi gắn trực tiếp với dấu hiệu quan sát được.

Nguồn cần kiểm tra lại khi sử dụng:

- [Metricool MCP và media trực tiếp](https://help.metricool.com/how-to-schedule-a-post-at-different-times-on-each-network-using-metricools-mcp-8tmhd)
- [TikTok: nhãn AI](https://support.tiktok.com/en/using-tiktok/creating-videos/ai-generated-content)
- [TikTok: công bố nội dung thương mại](https://support.tiktok.com/en/business-and-creator/creator-and-business-accounts/promoting-a-brand-product-or-service)
- [TikTok: nhạc dùng thương mại](https://support.tiktok.com/en/business-and-creator/creator-and-business-accounts/commercial-use-of-music-on-tiktok)
