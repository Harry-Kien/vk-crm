<?php

namespace Database\Seeders;

use App\Models\ChecklistTemplate;
use App\Models\MatterType;
use Illuminate\Database\Seeder;

class ChecklistTemplateSeeder extends Seeder
{
    /** SPEC §4.9: 12 đầu mục cho tranh chấp đất đai, mục bắt buộc 1, 2, 3, 10. @return list<array{0: string, 1: bool, 2: string}> */
    public static function landDisputeItems(): array
    {
        return [
            ['Giấy tờ tuỳ thân của người khởi kiện, bản sao chứng thực', true, 'Căn cước công dân hoặc hộ chiếu còn hiệu lực, sao y tại UBND hoặc văn phòng công chứng.'],
            ['Giấy chứng nhận quyền sử dụng đất hoặc giấy tờ về quyền sử dụng đất', true, 'Sổ đỏ, sổ hồng hoặc giấy tờ tương đương; nộp bản sao chứng thực, giữ bản chính.'],
            ['Biên bản hoà giải tại Uỷ ban nhân dân cấp xã', true, 'Bắt buộc phải có trước khi khởi kiện tranh chấp đất đai.'],
            ['Hợp đồng chuyển nhượng, tặng cho hoặc văn bản về thừa kế liên quan', false, 'Nếu có.'],
            ['Trích lục bản đồ địa chính, trích đo thửa đất', false, 'Xin tại văn phòng đăng ký đất đai.'],
            ['Văn bản, quyết định của cơ quan nhà nước liên quan đến thửa đất', false, 'Quyết định giao đất, thu hồi, xử phạt nếu có.'],
            ['Chứng cứ về quá trình sử dụng đất: biên lai thuế, hoá đơn điện nước', false, 'Càng nhiều năm càng tốt.'],
            ['Ảnh hiện trạng thửa đất và công trình trên đất', false, 'Chụp rõ ranh giới, mốc giới.'],
            ['Danh sách, địa chỉ người có quyền lợi và nghĩa vụ liên quan', false, 'Họ tên, địa chỉ, số điện thoại nếu biết.'],
            ['Hợp đồng dịch vụ pháp lý và giấy uỷ quyền', true, 'Văn phòng soạn, anh/chị ký.'],
            ['Giấy chứng tử và văn bản kê khai di sản, nếu có yếu tố thừa kế', false, 'Chỉ khi tranh chấp liên quan thừa kế.'],
            ['Tài liệu khác theo yêu cầu của toà án', false, 'Bổ sung khi toà yêu cầu.'],
        ];
    }

    public function run(): void
    {
        $this->template('DD', 'Danh mục hồ sơ tranh chấp đất đai', self::landDisputeItems());

        $this->template('DS', 'Danh mục hồ sơ tranh chấp dân sự', [
            ['Giấy tờ tuỳ thân của người khởi kiện, bản sao chứng thực', true, 'Căn cước hoặc hộ chiếu còn hiệu lực.'],
            ['Hợp đồng, giấy vay, biên nhận hoặc văn bản làm phát sinh tranh chấp', true, 'Bản gốc hoặc bản sao chứng thực.'],
            ['Chứng cứ giao dịch: chuyển khoản, tin nhắn, email', false, 'Chụp màn hình rõ ngày giờ.'],
            ['Hợp đồng dịch vụ pháp lý và giấy uỷ quyền', true, 'Văn phòng soạn, anh/chị ký.'],
            ['Tài liệu khác theo yêu cầu của toà án', false, 'Bổ sung khi toà yêu cầu.'],
        ]);

        $this->template('HN', 'Danh mục hồ sơ hôn nhân và gia đình', [
            ['Giấy tờ tuỳ thân hai bên, bản sao chứng thực', true, 'Căn cước hoặc hộ chiếu.'],
            ['Giấy chứng nhận kết hôn', true, 'Bản chính hoặc trích lục.'],
            ['Giấy khai sinh của con chung', false, 'Nếu có con chung.'],
            ['Giấy tờ về tài sản chung', false, 'Sổ đỏ, đăng ký xe, sổ tiết kiệm.'],
            ['Hợp đồng dịch vụ pháp lý và giấy uỷ quyền', true, 'Văn phòng soạn, anh/chị ký.'],
        ]);
    }

    /**
     * **Chỉ THÊM, không bao giờ sửa (final review X10, C-I5).** Một danh mục mẫu (kèm đầu mục) chỉ
     * được tạo khi loại vụ việc đó CHƯA có danh mục nào mang đúng tên này, kể cả đã xoá mềm. Danh
     * mục đã có thì bỏ qua hoàn toàn: đầu mục đã xoá không quay lại, mô tả/bắt buộc đã sửa giữ
     * nguyên, danh mục đã tắt vẫn tắt. Loại vụ việc không còn (đã xoá mềm hay chưa từng có) thì
     * không có gì để gắn danh mục vào.
     *
     * @param  list<array{0: string, 1: bool, 2: string}>  $items
     */
    private function template(string $typeCode, string $name, array $items): void
    {
        $type = MatterType::query()->where('code', $typeCode)->first();

        if ($type === null) {
            return;
        }

        $exists = ChecklistTemplate::withTrashed()
            ->where('matter_type_id', $type->id)
            ->where('name', $name)
            ->exists();

        if ($exists) {
            return;
        }

        $template = ChecklistTemplate::query()->create([
            'matter_type_id' => $type->id,
            'name' => $name,
            'is_active' => true,
        ]);

        foreach ($items as $index => [$itemName, $required, $description]) {
            $template->items()->create([
                'name' => $itemName,
                'description' => $description,
                'is_required' => $required,
                'sort_order' => $index + 1,
            ]);
        }
    }
}
