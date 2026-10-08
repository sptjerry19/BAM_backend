<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\News;
use App\Models\User;
use Illuminate\Database\Seeder;

class NewsSeeder extends Seeder
{
    public function run(): void
    {
        $userId = User::query()->where('role', 'admin')->value('id')
            ?? User::query()->value('id');

        if (! $userId) {
            $userId = User::query()->create([
                'name' => 'Ban biên tập',
                'email' => 'editor@badminton.local',
                'password' => 'password123',
                'role' => 'admin',
            ])->id;
        }

        $categories = [
            'Tin tức' => 'https://images.unsplash.com/photo-1626224583764-f87db24ac4ea?auto=format&fit=crop&w=800&q=80',
            'Giải đấu' => 'https://images.unsplash.com/photo-1461896836934-ffe607ba6851?auto=format&fit=crop&w=800&q=80',
            'Kỹ thuật' => 'https://images.unsplash.com/photo-1599391398131-cd12dfc6c24e?auto=format&fit=crop&w=800&q=80',
            'Câu lạc bộ' => 'https://images.unsplash.com/photo-1521412644187-c49fa049e84d?auto=format&fit=crop&w=800&q=80',
        ];

        $categoryIds = [];
        foreach ($categories as $name => $image) {
            $categoryIds[$name] = Category::query()->updateOrCreate(
                ['name' => $name],
                ['image' => $image],
            )->id;
        }

        foreach ($this->articles() as $article) {
            $category = $article['category'];
            unset($article['category']);

            News::query()->updateOrCreate(
                ['slug' => $article['slug']],
                array_merge($article, [
                    'user_id' => $userId,
                    'category_id' => $categoryIds[$category],
                    'body' => $article['content'],
                ]),
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function articles(): array
    {
        return [
            [
                'category' => 'Tin tức',
                'tag' => 'Nổi bật',
                'title' => 'Cầu lông phong trào Hà Nội bước vào mùa giải cuối năm',
                'slug' => 'cau-long-phong-trao-ha-noi-mua-giai-cuoi-nam',
                'excerpt' => 'Các câu lạc bộ nội thành đồng loạt mở lịch tập tối và giải giao hữu cuối tuần.',
                'date' => '2026-10-02',
                'read' => 6,
                'featured' => true,
                'cover_caption' => 'Buổi tập tối tại cụm sân Cầu Giấy.',
                'image' => 'https://images.unsplash.com/photo-1626224583764-f87db24ac4ea?auto=format&fit=crop&w=1600&q=80',
                'content' => $this->html(
                    'Các cụm sân nội thành đang kín lịch từ 18 giờ, đặc biệt vào thứ Ba và thứ Năm.',
                    [
                        'Lịch tập dày hơn' => 'Người chơi trình độ trung bình và nâng cao ưu tiên khung 19:00–21:00. Nhiều CLB chia sân theo cặp cố định để giữ nhịp tập.',
                        'Giải giao hữu cuối tuần' => 'Từ tháng 10, các nhóm ở Đống Đa, Cầu Giấy và Hà Đông luân phiên đăng cai một buổi đánh đồng đội, mỗi đội 4–6 người.',
                    ],
                ),
            ],
            [
                'category' => 'Giải đấu',
                'tag' => 'Giải đấu',
                'title' => 'JBadminton Open tháng 10: thể thức đôi nam và đôi nam nữ',
                'slug' => 'jbadminton-open-thang-10',
                'excerpt' => 'Giải mở rộng nội bộ diễn ra hai ngày cuối tuần, thang điểm tính vào bảng xếp hạng CLB.',
                'date' => '2026-09-28',
                'read' => 5,
                'featured' => true,
                'cover_caption' => 'Lưới và sân gỗ dùng cho vòng bảng.',
                'image' => 'https://images.unsplash.com/photo-1554068865-24cecd4e34b8?auto=format&fit=crop&w=1600&q=80',
                'content' => $this->html(
                    'Mỗi nội dung có vòng bảng rồi loại trực tiếp. Điểm thưởng cộng vào hồ sơ thành viên.',
                    [
                        'Thể thức' => 'Đôi nam và đôi nam nữ, mỗi trận đánh hai set 21 điểm. Hòa 1–1 thì set quyết định chơi đến 15.',
                        'Cách tính điểm' => 'Vô địch 120 điểm, á quân 80 điểm, bán kết 40 điểm. Thành viên trình độ mới bắt đầu thi đấu ở bảng riêng.',
                    ],
                ),
            ],
            [
                'category' => 'Kỹ thuật',
                'tag' => 'Kỹ thuật',
                'title' => 'Ba lỗi footwork khiến cú đập mất lực',
                'slug' => 'ba-loi-footwork-cu-dap',
                'excerpt' => 'Chân không vào đúng vị trí trước khi vung vợt là lý do phổ biến khiến đập cầu đi ngắn.',
                'date' => '2026-09-20',
                'read' => 7,
                'featured' => false,
                'cover_caption' => 'Bước cuối cùng quyết định điểm tiếp xúc.',
                'image' => 'https://images.unsplash.com/photo-1599391398131-cd12dfc6c24e?auto=format&fit=crop&w=1600&q=80',
                'content' => $this->html(
                    'Đập cầu cần chuỗi bước nhỏ, rồi một bước cuối rộng để vai xoay kịp.',
                    [
                        'Đứng chờ quá sớm' => 'Dừng chân trước khi cầu rơi khiến người chơi phải với. Hãy tiếp tục bước nhỏ cho tới khi cầu vào vùng đập.',
                        'Trọng tâm đổ về gót' => 'Nếu gót chạm sân trước mũi chân, lực truyền từ hông bị đứt. Giữ mũi chân tiếp đất khi tiếp xúc cầu.',
                        'Vung tay trước khi xoay hông' => 'Tay đi trước thân làm cổ tay gánh lực. Xoay hông một nhịp rồi mới đánh.',
                    ],
                ),
            ],
            [
                'category' => 'Câu lạc bộ',
                'tag' => 'CLB',
                'title' => 'CLB Đống Đa mở lớp giao cầu cho người mới',
                'slug' => 'clb-dong-da-lop-giao-cau',
                'excerpt' => 'Buổi tối thứ Tư dành cho thành viên mới, tập trung giao cầu thấp tay và di chuyển cơ bản.',
                'date' => '2026-09-18',
                'read' => 4,
                'featured' => false,
                'cover_caption' => 'Nhóm người mới tập giao cầu theo cặp.',
                'image' => 'https://images.unsplash.com/photo-1521412644187-c49fa049e84d?auto=format&fit=crop&w=1600&q=80',
                'content' => $this->html(
                    'Lớp kéo dài 75 phút, tối đa 8 người, có huấn luyện viên của CLB theo sát.',
                    [
                        'Nội dung buổi học' => 'Giao cầu thấp tay, đỡ giao và bước lùi về giữa sân. Không đánh tỷ số trong 30 phút đầu.',
                        'Cách đăng ký' => 'Thành viên chọn khung giờ trên trang đặt sân, ghi chú “lớp người mới”. Phí sân tính theo giờ như lịch thường.',
                    ],
                ),
            ],
            [
                'category' => 'Tin tức',
                'tag' => 'Tin tức',
                'title' => 'Chọn quả cầu cho buổi tập tối trong nhà',
                'slug' => 'chon-qua-cau-tap-toi',
                'excerpt' => 'Quả cầu nhựa ổn định hơn khi máy lạnh thổi lệch, cầu lông vũ hợp sân thi đấu.',
                'date' => '2026-09-12',
                'read' => 4,
                'featured' => false,
                'cover_caption' => 'Hộp cầu để sẵn tại quầy sân.',
                'image' => 'https://images.unsplash.com/photo-1517649763962-0c623066027b?auto=format&fit=crop&w=1600&q=80',
                'content' => $this->html(
                    'Nhiều sân bật điều hòa mạnh sau 19 giờ, cầu lông vũ đổi hướng rõ.',
                    [
                        'Tập kỹ thuật' => 'Dùng cầu nhựa tốc độ trung bình để giữ quỹ đạo đều khi luyện giao và phông.',
                        'Đánh trận' => 'Chuyển sang cầu lông vũ khi vào hiệp, và đổi quả sau mỗi 10–12 phút nếu lông tơi.',
                    ],
                ),
            ],
            [
                'category' => 'Giải đấu',
                'tag' => 'Giải đấu',
                'title' => 'Bảng xếp hạng đôi nam nữ sau vòng loại',
                'slug' => 'bang-xep-hang-doi-nam-nu',
                'excerpt' => 'Ba cặp dẫn đầu cách nhau chưa tới 30 điểm sau bốn trận vòng bảng.',
                'date' => '2026-09-08',
                'read' => 3,
                'featured' => false,
                'cover_caption' => 'Bảng điểm được cập nhật sau mỗi trận.',
                'image' => 'https://images.unsplash.com/photo-1574629810360-7efbbe195018?auto=format&fit=crop&w=1600&q=80',
                'content' => $this->html(
                    'Vòng loại kết thúc với hiệu số set rất sát ở nhóm giữa bảng.',
                    [
                        'Nhóm dẫn đầu' => 'Các cặp có tỷ lệ giao cầu ăn điểm trên 60% đang giữ vị trí cao hơn nhóm chơi phòng ngự.',
                        'Lịch bán kết' => 'Bán kết diễn ra tối thứ Bảy tại Olympic Badminton Center, mỗi cặp được 20 phút khởi động.',
                    ],
                ),
            ],
            [
                'category' => 'Kỹ thuật',
                'tag' => 'Kỹ thuật',
                'title' => 'Cách giữ nhịp khi đánh đôi phòng ngự',
                'slug' => 'nhip-danh-doi-phong-ngu',
                'excerpt' => 'Người phía trước chỉ cắt những quả ngang ngực, người sau giữ các quả cao.',
                'date' => '2026-09-04',
                'read' => 6,
                'featured' => false,
                'cover_caption' => 'Đôi phòng ngự đứng lệch trước sau.',
                'image' => 'https://images.unsplash.com/photo-1541534741688-6078c6bfb5c5?auto=format&fit=crop&w=1600&q=80',
                'content' => $this->html(
                    'Đôi phòng ngự vỡ nhịp khi cả hai cùng lùi hoặc cùng tiến.',
                    [
                        'Chia vùng' => 'Người trước phụ trách cầu ngang vai trở xuống. Người sau lo cầu bổng và đập.',
                        'Ra hiệu ngắn' => 'Một từ “của anh” hoặc “của em” trước khi cầu qua lưới đủ để tránh đụng vợt.',
                    ],
                ),
            ],
            [
                'category' => 'Câu lạc bộ',
                'tag' => 'CLB',
                'title' => 'Lịch bảo trì mặt sân gỗ trong tháng 10',
                'slug' => 'lich-bao-tri-san-go-thang-10',
                'excerpt' => 'Ba cụm sân gỗ đóng cửa buổi sáng để sơn lại vạch và kiểm tra lưới.',
                'date' => '2026-09-01',
                'read' => 3,
                'featured' => false,
                'cover_caption' => 'Vạch sân được sơn lại trước giờ mở cửa.',
                'image' => 'https://images.unsplash.com/photo-1571019614242-c5c5dee9f50b?auto=format&fit=crop&w=1600&q=80',
                'content' => $this->html(
                    'Buổi chiều và tối vẫn nhận khách. Khung 6:00–10:00 tạm ngưng tại một số sân.',
                    [
                        'Sân bị ảnh hưởng' => 'Hoàng Mai ngày 6/10, Cầu Giấy ngày 8/10, Olympic Badminton Center ngày 10/10.',
                        'Đặt chỗ đã có' => 'Các lượt đặt buổi sáng trong những ngày trên được chuyển sang sân PVC cùng cụm hoặc hoàn giờ.',
                    ],
                ),
            ],
            [
                'category' => 'Tin tức',
                'tag' => 'Tin tức',
                'title' => 'Vợt cân bằng 4U phù hợp người mới đánh đôi',
                'slug' => 'vot-4u-cho-nguoi-moi-danh-doi',
                'excerpt' => 'Thân vợt cân bằng giúp chuyển giữa phòng thủ và tấn công mà không mỏi cổ tay sớm.',
                'date' => '2026-08-26',
                'read' => 5,
                'featured' => false,
                'cover_caption' => 'Nhóm vợt tập trung tại quầy dụng cụ.',
                'image' => 'https://images.unsplash.com/photo-1517836357463-d25dfeac3438?auto=format&fit=crop&w=1600&q=80',
                'content' => $this->html(
                    'Người mới thường chọn vợt đầu nặng rồi mất kiểm soát ở những pha đỡ nhanh.',
                    [
                        'Trọng lượng' => 'Khung 4U khoảng 80–84 gram là mức dễ xoay cổ tay khi đỡ giao cầu.',
                        'Độ cứng' => 'Thân trung bình mềm tạo cảm giác cầu rõ hơn thân cứng khi lực đánh chưa ổn định.',
                    ],
                ),
            ],
            [
                'category' => 'Giải đấu',
                'tag' => 'Giải đấu',
                'title' => 'Luật thay người ở giải đồng đội nội bộ',
                'slug' => 'luat-thay-nguoi-giai-dong-doi',
                'excerpt' => 'Mỗi đội được đổi một vận động viên giữa các trận, không đổi giữa hiệp.',
                'date' => '2026-08-21',
                'read' => 4,
                'featured' => false,
                'cover_caption' => 'Đội hình được chốt trước giờ giao cầu.',
                'image' => 'https://images.unsplash.com/photo-1461896836934-ffe607ba6851?auto=format&fit=crop&w=1600&q=80',
                'content' => $this->html(
                    'Quy định mới giúp các đội có người mới vẫn tham gia mà không làm đứt trận.',
                    [
                        'Khi nào được đổi' => 'Chỉ giữa trận đơn và trận đôi kế tiếp. Trọng tài bàn ghi tên người vào sân.',
                        'Giới hạn' => 'Một lần đổi cho cả buổi. Vận động viên đã ra sân không được quay lại nội dung đó.',
                    ],
                ),
            ],
            [
                'category' => 'Kỹ thuật',
                'tag' => 'Kỹ thuật',
                'title' => 'Bài tập 15 phút trước giờ cao điểm',
                'slug' => 'bai-tap-15-phut-truoc-gio-cao-diem',
                'excerpt' => 'Khởi động ngắn giúp khớp gối và cổ tay sẵn sàng khi sân bắt đầu đông.',
                'date' => '2026-08-16',
                'read' => 4,
                'featured' => false,
                'cover_caption' => 'Khởi động dọc biên trước trận.',
                'image' => 'https://images.unsplash.com/photo-1518611012118-696072aa579a?auto=format&fit=crop&w=1600&q=80',
                'content' => $this->html(
                    'Đến sớm 15 phút là đủ cho một chuỗi khởi động không cần dụng cụ.',
                    [
                        'Phút 1–5' => 'Đi bộ nhanh quanh sân, xoay cổ chân và cổ tay.',
                        'Phút 6–10' => 'Chạy bước nhỏ tiến lùi, rồi nhảy nhẹ tại chỗ.',
                        'Phút 11–15' => 'Giao cầu 20 quả mỗi bên, bắt đầu chậm rồi tăng tốc.',
                    ],
                ),
            ],
            [
                'category' => 'Câu lạc bộ',
                'tag' => 'CLB',
                'title' => 'Nhóm sáng sớm tại Hà Đông nhận thêm một sân',
                'slug' => 'nhom-sang-som-ha-dong',
                'excerpt' => 'Khung 5:30–7:30 có thêm sân số 4 dành cho thành viên đặt cố định trong tuần.',
                'date' => '2026-08-10',
                'read' => 3,
                'featured' => false,
                'cover_caption' => 'Sân mở đèn từ 5 giờ 15.',
                'image' => 'https://images.unsplash.com/photo-1431324155629-1a6deb1dec8d?auto=format&fit=crop&w=1600&q=80',
                'content' => $this->html(
                    'Nhóm sáng sớm trước đây phải chờ vì chỉ có hai sân PVC.',
                    [
                        'Suất cố định' => 'Thành viên giữ suất bằng cách đặt lặp lại trước 21 giờ hôm trước.',
                        'Khách vãng lai' => 'Sân còn trống được mở lúc 5:20 nếu suất cố định không nhận.',
                    ],
                ),
            ],
        ];
    }

    /**
     * @param  array<string, string>  $sections
     */
    private function html(string $lead, array $sections): string
    {
        $html = '<p>'.$lead.'</p>';
        foreach ($sections as $heading => $paragraph) {
            $html .= '<h2>'.$heading.'</h2><p>'.$paragraph.'</p>';
        }

        return $html;
    }
}
