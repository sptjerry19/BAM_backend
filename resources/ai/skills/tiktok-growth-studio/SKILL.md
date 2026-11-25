---
name: tiktok-growth-studio
description: Nghiên cứu và phân tích trend TikTok làm đầu vào tạo video/ảnh cho Jerry Badminton; lưu nội dung mới vào schedule, video trend tải về vào reup và xuất report có thời gian đăng, content, src media. Dùng khi yêu cầu bắt trend, sản xuất nội dung, tải video tham khảo/reup hoặc lập lịch nội dung; không dùng cho lập trình tính năng mạng xã hội.
---

# TikTok Growth Studio

Biến tín hiệu xu hướng thành nội dung đáng xem cho đúng khán giả, rồi kiểm chứng bằng dữ liệu. Không coi “đã render được MP4” là “nội dung đủ tốt để xuất bản”; không bảo đảm lên xu hướng hoặc có thu nhập.

## Ngữ cảnh và chế độ làm việc

- Khi làm cho Jerry Badminton, đọc [references/channel-context.md](references/channel-context.md). Xác minh lại kênh, lịch và công cụ trước hành động bên ngoài; các ID trong ngữ cảnh chỉ là dữ kiện đã quan sát.
- Mặc định ưu tiên miễn phí, tiếng Việt, khán giả Việt Nam. Người dùng ít quay mới; tận dụng clip do họ sở hữu, hình/hoạt cảnh tự sáng tác và tài nguyên có giấy phép phù hợp.
- Với Jerry Badminton, ưu tiên nội dung giúp người chơi chọn trang bị: phân tích điểm mạnh/yếu có căn cứ, so sánh vợt cùng phân khúc và chọn theo trình độ/lối chơi/ngân sách. Meme và highlight chỉ bổ trợ khi có lý do phù hợp khán giả; không mặc định biến mọi clip nhiều view thành meme AI. Đọc [references/equipment-content.md](references/equipment-content.md) khi lên kế hoạch hoặc phân tích sản phẩm.
- Giữ nguyên phạm vi yêu cầu hiện tại: tạo/cài skill không phải lệnh đăng một chiến dịch mới; phân tích không phải lệnh thay đổi lịch. Khi người dùng giao sản xuất và tự đăng, tiếp tục đến bước xác minh lịch đã lưu trong phạm vi đó, không hỏi lại các quyết định đã được cho phép.
- Nếu có ủy quyền đăng định kỳ, giữ rõ tài khoản, số bài tối đa, chủ đề, ngân sách, khoảng thời gian và các loại nội dung cần chuyển lại cho người dùng. Chỉ hỏi phần thực sự còn thiếu; không coi việc cài skill là quyền đăng vô thời hạn.
- Có thể chạy riêng từng chế độ: nghiên cứu, sản xuất, lên lịch hoặc đánh giá. Không bắt làm lại bước đã có bằng chứng đủ mới.

## Đầu ra bắt buộc khi chạy đầy đủ

Mỗi lần chạy sản xuất mặc định phải giao **3 nội dung ảnh/video tự tạo và 3 video nguồn đã tải thật**, trừ khi người dùng chỉ định số lượng khác hoặc chỉ yêu cầu nghiên cứu/đánh giá/lên lịch file có sẵn. Không coi “tạo video tuần này” là chỉ tạo 3 video rồi bỏ phần tải nguồn.

- `schedule/`: đúng 3 nội dung mới, khác ý tưởng; mỗi nội dung có một bản cuối. Một carousel là một nội dung, không đếm từng ảnh/cảnh/phiên bản thành nhiều nội dung.
- `reup/`: 3 video nguồn khác nhau, tải thành file local có dữ liệu, giải mã được, kèm provenance. URL, thumbnail, prompt, file trùng hoặc video tự tạo chép sang không được tính vào 3 video tải về.
- Tìm nguồn trên TikTok, Douyin, YouTube/Shorts và nền tảng khác phù hợp; không giới hạn ở TikTok hoặc chỉ dừng sau một trang Creative Center. Tín hiệu ở thị trường/nền tảng khác phải ghi rõ, không tự coi là trend TikTok Việt Nam.
- Nếu nguồn bị chặn, tiếp tục tìm video/nguồn thay thế bằng cách tải được phép. Phân biệt quyền tải để nghiên cứu và quyền đăng lại; chưa có quyền reup không tự động loại nguồn được phép lưu tham khảo. Không tự thay 3 video tải về bằng thêm ảnh AI.
- Chỉ báo hoàn tất khi đủ 3 nội dung mới và 3 video tải hợp lệ. Nếu đã thử các nguồn/phương thức khả dụng mà vẫn thiếu hoặc còn vướng quyền truy cập/công cụ, giao phần có thật, ghi `incomplete`, số còn thiếu và từng lần thử/lý do trong report. Không lặp vô hạn vào cùng lỗi, bịa file hay gọi đợt thiếu là hoàn thành.

Đọc [references/storage-and-report.md](references/storage-and-report.md) trước khi lưu file hoặc tạo report. Chọn một thư mục chiến dịch bên trong workspace; nếu người dùng không chỉ định, dùng `social-content-starter/campaigns/YYYY-MM-DD/`, thêm hậu tố nếu đã có đợt khác cùng ngày. Dùng ngày client, không tự ghi đè chiến dịch cũ.

```text
<campaign>/
  research.md
  schedule/<content-id>/final-v1.mp4 hoặc các ảnh đánh số
  reup/<source-id>/source.mp4 và provenance.json
  report.json
  report.md
```

- `schedule/`: ảnh/video cuối do mình tạo hoặc dựng lại, kèm caption và kết quả QA. Tên thư mục là hàng chờ nội dung; không phải bằng chứng đã lưu lịch trên nền tảng. Không để render lỗi/bản nháp trong thư mục này; dùng `work/` nếu cần.
- `reup/`: video xu hướng đã tải được qua phương thức được phép, giữ bản nguồn và thông tin nguồn/quyền. Video chỉ được xem công khai không mặc định có quyền đăng lại.
- Report phải có từng nội dung và từng video tải về: **thời gian đăng, content, src vid/img**, cùng trạng thái. Với video chưa được phép đăng lại, thời gian là `null` và nêu lý do, không tự đưa vào lịch.
- Không tạo file media rỗng hoặc ghi “đã tải/đã tạo” khi chỉ có URL/prompt. Nếu bị chặn tải hoặc thiếu năng lực tạo, vẫn ghi trạng thái thật vào report.

## 1. Tìm xu hướng và chọn ý tưởng

Đọc [references/trend-research.md](references/trend-research.md) khi nghiên cứu.

- Nghiên cứu cả nhạc/sound, caption và hashtag theo [references/audio-and-discovery.md](references/audio-and-discovery.md). Report của từng nội dung mới và video tải về phải có phương án âm thanh, căn cứ chọn nhạc, caption cuối và hashtag có lý do. Không coi caption/nhạc là phần bổ sung tùy ý sau khi đã render.

- Dùng nguồn hiện tại: TikTok Creative Center theo Việt Nam và cửa sổ thời gian đang chọn, kết quả tìm kiếm/video gốc, công cụ nghiên cứu đã kết nối và số liệu kênh Metricool. Metricool analytics không tự đại diện cho xu hướng toàn TikTok.
- Xem video nguồn thực tế khi cần phân tích hook, nhịp dựng hoặc âm thanh. Nếu chỉ đọc caption/snippet, nói rõ chưa xem video; không bịa phân tích từng cảnh.
- Phân biệt `verified-trend`, `hypothesis`, `evergreen`. “Verified” nghĩa là có bằng chứng xu hướng cụ thể theo nguồn, không phải chắc chắn viral. Ghi URL, thời điểm quan sát, vùng/thời gian lọc và chỉ số nhìn thấy; không dựng tốc độ tăng từ một ảnh chụp số view.
- Chọn ý theo độ phù hợp người chơi cầu lông, tính mới của cách thể hiện, tín hiệu xu hướng, khả năng sản xuất và quyền dùng tài nguyên. Không đuổi theo trend xa chủ đề chỉ vì nhiều view.
- Mỗi đợt mặc định chọn 3 ý tốt, mỗi ý có 2–3 hook khác nhau trên giấy. Chọn một bản mỗi ý để sản xuất; không đăng hàng loạt bản gần giống để thử hook.
- Nếu nguồn bị chặn hoặc cần người dùng đăng nhập, tiếp tục với nguồn hợp lệ khác. Nếu không có bằng chứng mới, gọi ý tưởng là giả thuyết hoặc thường xanh, không gọi “trend hôm nay”.
- Ghi kết quả vào `research.md`: nguồn, quan sát, phân loại trend, khán giả, cơ chế thu hút, lý do chọn/loại. Sau đó chuyển thành brief cho bước 2: **trend nào → giữ cơ chế gì → biến tấu cầu lông thế nào → cần asset nào → đo kết quả bằng gì**. Không nghiên cứu một nhóm trend rồi tạo video không liên quan mà không giải thích quyết định.
- Với mỗi ý được chọn, phân tích các yếu tố thật sự đã xem: hình mở đầu, câu mở, nhịp cảnh, âm thanh, điểm bất ngờ/giá trị và lý do muốn xem lại/theo dõi. Không coi sao chép hashtag là đáp ứng xu hướng.
- Trước khi tạo asset, chốt `content-plan.md` theo reference trang bị: nhu cầu cụ thể của người xem, bằng chứng nhu cầu khác với bằng chứng viral, câu hỏi bài phải trả lời, kết luận có nguồn, dàn ý từng ảnh/cảnh và chỉ số kiểm tra. Tự hoàn thiện kế hoạch trong phạm vi được giao, không mặc định yêu cầu người dùng duyệt. Nếu chưa trả lời được bài giúp ai quyết định gì và dựa vào đâu, tiếp tục nghiên cứu hoặc đổi ý; không sản xuất chỉ để đủ 3 file.

## 2. Tạo ảnh/video đáp ứng insight xu hướng → schedule/

Đọc [references/production-and-publishing.md](references/production-and-publishing.md) để biết gói sản xuất, kiểm tra video, media hosting và đăng bài.

- Khám phá công cụ đang có trước. Tạo ảnh bằng công cụ image generation hiện có khi phù hợp; đọc skill tương ứng khi dùng. Dựng/cắt/ghép/phụ đề bằng FFmpeg cho clip và media có sẵn. Tạo video AI chuyển động chỉ khi công cụ thực sự hỗ trợ.
- Nếu thiếu năng lực, tìm tích hợp bằng tên dịch vụ hoặc chức năng. Runway là một lựa chọn tạo video/âm thanh; vidIQ là một lựa chọn nghiên cứu. Chỉ dùng sau khi xác nhận kết nối; không giả định đã cài từ việc đề xuất.
- Kiểm tra credit/giá/điều kiện sử dụng hiện tại trước khi chạy. Ngân sách mặc định cho dịch vụ ngoài là 0; nếu không có lượt miễn phí đã xác nhận, dùng nhánh miễn phí hoặc hỏi về phần chi phí cụ thể. Không tự đăng ký, mua gói hay gọi job trả phí.
- Viết brief ngắn trước khi dựng: ai xem, cảm xúc/vấn đề, bằng chứng xu hướng, hook, diễn biến, payoff, hình/âm thanh cần dùng và CTA. Chọn thời lượng theo lượng thông tin, không kéo dài chỉ để đủ một mốc.
- Mở bằng hành động, tình huống hoặc câu hỏi có hình ảnh trả lời được ngay. Tránh logo/tiêu đề mở đầu dài; chữ hỗ trợ câu chuyện thay vì lấp đầy màn hình.
- Với meme, phải có thiết lập và cú chuyển/punchline thể hiện bằng cảnh, diễn xuất hoặc hoạt họa. Với giải thích, phải cho thấy ví dụ/minh họa có ý nghĩa. Chuyển động cần phục vụ ý, không chỉ zoom một tấm ảnh để gọi là video hấp dẫn.
- Không dùng slide chữ tĩnh + hình vợt/cầu đơn giản như sản phẩm hoàn chỉnh mặc định cho kênh này. Nếu chỉ có thể làm bản thử kỹ thuật, ghi rõ và không tự đưa nó vào lịch xuất bản.
- Có thể thiết kế nội dung xem không tiếng nhưng phải có lý do biên tập; ưu tiên âm thanh tự thu, giọng đọc hoặc SFX/nhạc có quyền phù hợp khi tăng được chất lượng. Không tự nhân bản giọng người khác.
- Kiểm tra toàn bộ bản cuối với âm thanh, nhịp dựng, lỗi hình và vùng chữ bị giao diện TikTok che. Không chỉ đọc kịch bản hoặc xem thumbnail rồi kết luận đạt chất lượng.
- Tập trung cơ chế giúp tăng view/follow: hook khớp nội dung và được trả lời trong video; nhịp diễn biến giữ sự chú ý; payoff hoặc thông tin đáng lưu/chia sẻ; một lý do rõ để theo dõi series cầu lông. Không kéo view bằng lời hứa sai hoặc nội dung xa đối tượng mua hàng tương lai.
- Với video, thiết kế storyboard có mốc thời gian và hành động cụ thể. So sánh 2–3 phương án hook trước khi dựng, chọn cách mở có hình chứng minh được ngay. Nhịp cắt theo tình huống/âm thanh, không áp cố định phải đổi cảnh mỗi giây. Loop chỉ dùng nếu tự nhiên và không che mất kết quả.
- Với ảnh/carousel, ảnh đầu phải nêu được vấn đề/hứa hẹn đúng; mỗi ảnh sau thêm giá trị hoặc diễn biến; chữ ít và dễ đọc; ảnh cuối trả lời và gợi một series liên quan. Không xuất toàn bộ câu chuyện thành một tấm đầy chữ nếu định dạng không phù hợp.
- Lưu bản cuối vào `schedule/<content-id>/`, đặt phiên bản rõ ràng; report liên kết đúng phiên bản cuối. Bản chờ người dùng xem/nghe vẫn được lưu nhưng phải gắn `awaiting_review`, không giả định đạt QA vì đã ở `schedule/`.
- “Làm để có khả năng lên xu hướng” là mục tiêu thử nghiệm, không phải kết quả có thể cam kết. Nêu giả thuyết tăng trưởng và chỉ số kiểm tra; chỉ gọi hiệu quả tốt hơn sau khi có số liệu so sánh phù hợp.

## 3. Tải video xu hướng → reup/

- Mỗi video tải về phải có `caption.txt` tiếng Việt đề xuất và caption đầy đủ trong report, gồm hook ngắn, nội dung khớp cảnh đã xem, nguồn creator và hashtag liên quan. Không nhận là clip tự quay hoặc bịa lời nói/trải nghiệm. Với video `reference_only`/chưa đủ quyền đăng, vẫn viết caption nhưng gắn rõ là đề xuất chờ đủ quyền, giữ `publish_at: null`; viết caption không đồng nghĩa được phép hoặc đã đăng. Tách caption đề xuất khỏi caption gốc của tác giả.
- Mục tiêu mặc định là 3 video tải thật, khác nhau. Tìm thêm trên Douyin và YouTube/Shorts khi TikTok thiếu nguồn phù hợp; tìm bằng tiếng Việt, tiếng Anh hoặc tiếng Trung theo nội dung cầu lông. Ưu tiên nguồn gốc và video có bằng chứng phù hợp; không tải ngẫu nhiên cho đủ số. Ghi nền tảng, thị trường và mức độ chắc chắn của bằng chứng trend.
- Từ nguồn nghiên cứu ở bước 1, chọn video minh họa đúng cơ chế đã phân tích. Dùng chức năng tải chính thức, file chủ quyền cung cấp hoặc đường media/phương thức tải được cho phép; không chỉ dừng ở chép URL nếu thực tế tải được.
- Lưu video nguồn vào `reup/<source-id>/source.<ext>`, cùng `provenance.json` có URL gốc, creator nếu biết, thời gian tải/quan sát, bằng chứng trend, checksum và trạng thái quyền. Xác minh file có dữ liệu và phát/giải mã được; tránh tải cùng video nhiều lần.
- Phân biệt quyền lưu tham khảo và quyền xuất bản. Với nguồn tải hợp lệ nhưng chưa có quyền đăng lại, ghi `reference_only` hoặc `rights_unverified`; giữ ở `reup/`, report `publish_at: null`. Không giả định tên folder reup là quyền reup công khai.
- Nếu nền tảng/nguồn không cho tải hoặc phương thức hiện có không truy cập được, ghi `download_blocked` cùng URL và lý do; không vượt DRM/đăng nhập, dùng cookie lấy trộm hoặc bịa file tải thành công.
- Có thể dựng lại hoặc đăng clip do người dùng sở hữu, clip có phép của chủ quyền, hoặc tài nguyên có giấy phép cho phép cách sử dụng dự định. Lưu căn cứ quyền cho từng asset, gồm âm thanh, hình ảnh và cảnh quay.
- Dùng video trend làm tài liệu nghiên cứu không mặc nhiên cho phép tải xuống và reup. Ghi nguồn, cắt khung, tăng tốc, thêm phụ đề hoặc đổi nhạc không tạo ra quyền sử dụng mới. Không hướng dẫn xóa watermark/né phát hiện để đăng lại trái phép.
- Nếu chưa có quyền, học cấu trúc/ý tưởng rồi tự tạo phiên bản riêng hoặc dùng chức năng chia sẻ/remix của nền tảng trong phạm vi được cho phép; không giả định Duet/Stitch tự cấp phép xuất file để đăng ngoài chức năng đó.
- Không biến review AI thành lời chứng thực đã dùng thật; cảnh quảng bá phải phản ánh đúng sản phẩm. Kiểm tra yêu cầu nhãn AI, công bố thương mại và quyền âm nhạc của nội dung thực tế trước khi đăng.

## 4. Tạo report: thời gian đăng + content + src vid/img

- Xuất `report.json` để máy đọc và `report.md` để người dùng xem, theo [references/storage-and-report.md](references/storage-and-report.md). Đây là đầu ra của mọi lần chạy đầy đủ, kể cả khi có hạng mục bị chặn.
- Mỗi bản trong `schedule/` và mỗi nguồn trong `reup/` có một record riêng; nếu dùng lại nguồn để sản xuất, record mới liên kết record nguồn thay vì nhận là nội dung hoàn toàn không có nguồn.
- Luôn ghi: thời gian đăng ISO 8601 có timezone hoặc `null` có lý do; content gồm hook/tóm tắt/caption; src gồm đường dẫn file cuối và URL nguồn gốc tách biệt. Với carousel, ghi đúng thứ tự các ảnh.
- Bổ sung phân loại trend, trạng thái quyền/QA, `schedule_status`, và ID/link Metricool khi thực sự đã lưu. Không biến giờ đề xuất thành “đã lên lịch”.
- Có mục riêng cho **âm thanh/nhạc xu hướng, caption và hashtag** ở từng record: phân biệt tiếng đã có trong file với nhạc mới đề xuất, bằng chứng trend với độ hợp nội dung, và nhạc đã gắn với bước còn phải làm trên TikTok. Nếu chưa xác minh được bài nhạc hoặc chưa nghe được, ghi đúng thiếu sót; không tự điền tên “nhạc hot” từ trí nhớ.
- Kiểm tra các đường dẫn local tồn tại, caption đúng bản, thời gian tương lai nếu còn là đề xuất và số record khớp đầu ra. Đối chiếu riêng chỉ tiêu mặc định 3 nội dung mới + 3 video tải; báo số thực tế và phần còn thiếu. Sau khi thay file, đổi giờ hoặc lưu lịch thành công, cập nhật report tương ứng.

## 5. Lên lịch khi được yêu cầu và xác minh

- Đọc lịch hiện có trước khi tạo mới; xác minh đúng brand/network, múi giờ, hạn mức và media cuối. Không sửa/xóa bài cũ chỉ vì người dùng chê phong cách; cần có yêu cầu sửa/hủy bài đó.
- Kiểm tra chất lượng biên tập và quyền tài nguyên trước khi chạy kiểm tra kỹ thuật. Dùng `scripts/inspect_video.py` cho MP4; script không đánh giá được tính hấp dẫn, bản quyền hoặc quyền đăng.
- Ưu tiên công cụ Metricool đã kết nối. Lấy giờ gợi ý theo khoảng tối đa một tuần khi không có giờ cố định; coi đó là giả thuyết để thử. Không gọi một khung giờ là nguyên nhân bảo đảm viral.
- Media phải truy cập được bằng đường dẫn do dịch vụ hỗ trợ. File trên máy không phải URL công khai. Tải đúng file cuối và xác minh asset; không công khai file khác hoặc thông tin xác thực để giải quyết hosting.
- Với lời gọi tạo bài có kết quả không rõ/timeout, đọc lại lịch và đối chiếu kênh, giờ, caption, media trước khi thử tiếp. Không retry mù tạo bài trùng; không tự chuyển sang đăng ngay nếu lên lịch thất bại.
- Báo thành công chỉ sau readback cho thấy bài, network, media, ngày/giờ và Auto publish đúng; phân biệt `PENDING`, `PUBLISHED`, bản nháp và lỗi. Lưu biên nhận có ID/UUID và trạng thái; không lưu secret.
- Nếu phải dùng giao diện, áp dụng hướng dẫn computer-use của môi trường. Nếu cần người dùng xử lý đăng nhập/hộp file, hoàn tất mọi phần độc lập trước rồi nêu đúng bước vướng.
- Skill là quy trình, không phải daemon. Chỉ nói chạy tự động định kỳ sau khi tác vụ đã được tạo trong một scheduler thực tế và đọc lại cấu hình thành công. Nếu chỉ mới lên lịch một số bài, nói đúng số bài và thời gian đã lưu.
- Khi yêu cầu chỉ là nghiên cứu/tạo file/tạo report, dừng ở hàng chờ và giờ đề xuất. Khi yêu cầu có đăng/lên lịch, tiếp tục qua Metricool với các mục đủ QA và quyền sử dụng; cập nhật report sau readback.

## 6. Tối ưu sau khi đăng

- Chụp số liệu sau cùng một độ tuổi bài, ví dụ 24h và 72h; phân biệt tổng view tích lũy với view trong kỳ. Với kênh mới, xác minh 0/null có phải thiếu dữ liệu trước khi đánh giá.
- Ưu tiên retention/thời gian xem/xem hết nếu có; bổ sung share/comment, lượt vào hồ sơ và tăng follower ròng. Không tự suy retention từ like; không quy follower cho video khi nguồn không hỗ trợ.
- So sánh nội dung cùng nhóm độ dài/mục đích, dùng trung vị nếu ít bài bị một clip vượt trội làm lệch. Với mẫu nhỏ, nêu kết luận là tín hiệu tạm thời, không nguyên nhân đã chứng minh.
- Bỏ ở đầu: sửa hook và hình mở. Xem nhưng ít follow: làm rõ series và lời hứa của kênh. Có tương tác nhưng ít mua: xem độ phù hợp sản phẩm và bằng chứng sử dụng khi đủ quyền affiliate.
- Mỗi vòng chỉ đổi một vài yếu tố có chủ đích; tạo clip mới theo insight, không spam lại cùng video. Không mua follow/view, tạo bình luận giả hoặc dùng tài khoản phụ để thao túng tương tác.
- Tóm tắt gọn: nguồn nào đã xem; video/file nào đã tạo; kết quả kiểm tra; bài nào đã lên lịch; thử nghiệm tiếp theo và phần thực sự còn vướng.

## Cách gọi

- “Dùng $tiktok-growth-studio nghiên cứu trend cầu lông ở Việt Nam tuần này và chọn 3 ý có nguồn.”
- “Dùng $tiktok-growth-studio làm một video từ ý đã chọn, miễn phí; dựng bản cuối và kiểm tra trước khi đăng.”
- “Dùng $tiktok-growth-studio lên lịch các video đã đạt chất lượng cho @jerrybadminton tuần tới, tối đa 3 bài, rồi xác minh trong Metricool.”
- “Dùng $tiktok-growth-studio đọc hiệu quả các bài đã đăng và đề xuất thay đổi hook cho đợt sau.”
- “Dùng $tiktok-growth-studio nghiên cứu trend tuần này, tạo 3 nội dung vào schedule, tải 3 video nguồn từ TikTok/Douyin/YouTube vào reup và xuất report gồm thời gian đăng, content, src vid/img; chưa đăng.”
