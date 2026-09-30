<?php

namespace Database\Seeders;

use App\Filament\Admin\Resources\Matters\RelationManagers\BillingRelationManager;
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
            self::serviceContractItem(),
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
            self::serviceContractItem(),
            ['Tài liệu khác theo yêu cầu của toà án', false, 'Bổ sung khi toà yêu cầu.'],
        ]);

        $this->template('HN', 'Danh mục hồ sơ hôn nhân và gia đình', [
            ['Giấy tờ tuỳ thân hai bên, bản sao chứng thực', true, 'Căn cước hoặc hộ chiếu.'],
            ['Giấy chứng nhận kết hôn', true, 'Bản chính hoặc trích lục.'],
            ['Giấy khai sinh của con chung', false, 'Nếu có con chung.'],
            ['Giấy tờ về tài sản chung', false, 'Sổ đỏ, đăng ký xe, sổ tiết kiệm.'],
            self::serviceContractItem(),
        ]);

        // M9 Task 1: mỗi loại vụ việc còn lại có ít nhất một danh mục tối thiểu, để
        // `ApplyChecklistTemplate` không sinh ra một vụ việc không có danh mục nào. Chỉ là khung
        // tối thiểu (giấy tờ tuỳ thân, tài liệu của chính vụ việc, hợp đồng dịch vụ); văn phòng
        // bổ sung đầu mục theo lĩnh vực trong màn hình danh mục hồ sơ. Đầu mục hợp đồng dịch vụ
        // lấy tên từ `serviceContractItem()` — xem docblock của nó.
        $this->template('HS', 'Danh mục hồ sơ hình sự', [
            $this->identityItem('Căn cước hoặc hộ chiếu còn hiệu lực của người được bào chữa hoặc bảo vệ.'),
            ['Quyết định, giấy triệu tập, biên bản của cơ quan tiến hành tố tụng', true, 'Quyết định khởi tố, lệnh tạm giữ, giấy triệu tập; nộp bản sao.'],
            ['Tài liệu, chứng cứ liên quan đến vụ án', false, 'Bản sao mọi tài liệu anh/chị đang giữ.'],
            self::serviceContractItem(),
        ]);

        $this->template('DN', 'Danh mục hồ sơ đầu tư và doanh nghiệp', [
            $this->identityItem('Căn cước hoặc hộ chiếu của người đại diện theo pháp luật.'),
            ['Giấy chứng nhận đăng ký doanh nghiệp hoặc đăng ký đầu tư', true, 'Bản sao chứng thực. Doanh nghiệp đang thành lập thì văn phòng sẽ đánh dấu đầu mục này là không cần nộp.'],
            ['Điều lệ công ty và danh sách thành viên hoặc cổ đông', false, 'Bản mới nhất đang có hiệu lực.'],
            ['Tài liệu liên quan đến nội dung cần tư vấn hoặc thủ tục', false, 'Biên bản họp, nghị quyết, hợp đồng, công văn đã nhận.'],
            self::serviceContractItem(),
        ]);

        $this->template('LD', 'Danh mục hồ sơ lao động và nhân sự', [
            $this->identityItem('Căn cước hoặc hộ chiếu còn hiệu lực của người lao động hoặc người sử dụng lao động.'),
            ['Hợp đồng lao động và các phụ lục', true, 'Bản gốc hoặc bản sao chứng thực.'],
            ['Quyết định, thông báo của người sử dụng lao động', false, 'Quyết định kỷ luật, chấm dứt hợp đồng, điều chuyển nếu có.'],
            ['Bảng lương, sổ bảo hiểm xã hội, chứng từ trả lương', false, 'Sao chụp rõ ràng các kỳ gần nhất.'],
            self::serviceContractItem(),
        ]);

        $this->template('HC', 'Danh mục hồ sơ hành chính và giấy phép', [
            $this->identityItem('Căn cước hoặc hộ chiếu của cá nhân, hoặc của người đại diện của tổ chức.'),
            ['Quyết định, thông báo hoặc văn bản của cơ quan nhà nước liên quan', true, 'Bản sao quyết định hành chính hoặc văn bản trả lời hồ sơ.'],
            ['Giấy phép, giấy chứng nhận đã được cấp', false, 'Nếu có.'],
            ['Hồ sơ đã nộp cho cơ quan nhà nước', false, 'Bản sao hồ sơ, biên nhận, phiếu hẹn trả kết quả.'],
            self::serviceContractItem(),
        ]);

        $this->template('TM', 'Danh mục hồ sơ hợp đồng và thương mại', [
            $this->identityItem('Căn cước hoặc hộ chiếu của cá nhân, hoặc của người đại diện của doanh nghiệp.'),
            ['Hợp đồng, phụ lục và các văn bản thoả thuận liên quan', true, 'Bản đã ký hoặc bản dự thảo cần rà soát.'],
            ['Chứng từ thực hiện hợp đồng: đơn đặt hàng, biên bản giao nhận, hoá đơn', false, 'Nếu có.'],
            ['Thư từ, email trao đổi giữa các bên', false, 'Chụp màn hình hoặc xuất tệp, rõ ngày giờ.'],
            self::serviceContractItem(),
        ]);

        $this->template('NH', 'Danh mục hồ sơ ngân hàng và tín dụng', [
            $this->identityItem('Căn cước hoặc hộ chiếu của bên vay hoặc bên bảo lãnh.'),
            ['Hợp đồng tín dụng, hợp đồng thế chấp hoặc bảo lãnh', true, 'Bản sao đầy đủ các trang, kèm phụ lục.'],
            ['Thông báo, văn bản của ngân hàng về nợ và xử lý tài sản bảo đảm', false, 'Thông báo nợ quá hạn, yêu cầu trả nợ nếu có.'],
            ['Chứng từ trả nợ và sao kê tài khoản', false, 'Các kỳ liên quan đến tranh chấp.'],
            self::serviceContractItem(),
        ]);

        $this->template('SH', 'Danh mục hồ sơ sở hữu trí tuệ và công nghệ', [
            $this->identityItem('Căn cước hoặc hộ chiếu của cá nhân, hoặc của người đại diện của chủ sở hữu.'),
            ['Tài liệu chứng minh quyền: văn bằng bảo hộ hoặc bằng chứng sáng tạo', true, 'Văn bằng, đơn đăng ký, bản thảo, tệp gốc có ngày tạo.'],
            ['Mẫu nhãn hiệu, tác phẩm, sản phẩm hoặc phần mềm liên quan', false, 'Hình ảnh, tệp hoặc mô tả rõ ràng.'],
            ['Chứng cứ về hành vi xâm phạm hoặc nội dung thoả thuận chuyển giao', false, 'Ảnh chụp, đường dẫn, hợp đồng li-xăng nếu có.'],
            self::serviceContractItem(),
        ]);

        $this->template('TC', 'Danh mục hồ sơ thuế và tài chính', [
            $this->identityItem('Căn cước hoặc hộ chiếu của cá nhân, hoặc của người đại diện của doanh nghiệp.'),
            ['Thông báo, quyết định hoặc biên bản của cơ quan thuế', true, 'Thông báo nợ thuế, quyết định thanh tra, quyết định xử phạt.'],
            ['Tờ khai và chứng từ nộp thuế liên quan', false, 'Các kỳ tính thuế có liên quan.'],
            ['Báo cáo tài chính, sổ sách kế toán liên quan', false, 'Nếu có.'],
            self::serviceContractItem(),
        ]);

        $this->template('XD', 'Danh mục hồ sơ xây dựng và hạ tầng', [
            $this->identityItem('Căn cước hoặc hộ chiếu của cá nhân, hoặc của người đại diện của chủ đầu tư hoặc nhà thầu.'),
            ['Hợp đồng xây dựng, hợp đồng thầu phụ và các phụ lục', true, 'Bản sao đầy đủ, kèm phụ lục khối lượng và giá.'],
            ['Giấy phép xây dựng, hồ sơ thiết kế, quyết định phê duyệt dự án', false, 'Nếu có.'],
            ['Biên bản nghiệm thu, thanh toán, nhật ký thi công', false, 'Các đợt liên quan đến tranh chấp hoặc thủ tục.'],
            self::serviceContractItem(),
        ]);
    }

    /**
     * Đầu mục ✱ bắt buộc "hợp đồng dịch vụ pháp lý" (SPEC §4.9) của mọi mẫu seed. Tên KHÔNG chép
     * ở đây: tab Thanh toán (M9 Task 7) nhận diện đầu mục này chỉ bằng tên, qua
     * `BillingRelationManager::REQUIRED_CHECKLIST_ITEM_NAME` (`checklistNudge()` nhắc "chưa có hợp
     * đồng dịch vụ" khi đầu mục còn thiếu), nên seeder đọc đúng hằng số đó — đổi tên là đổi ở hằng
     * số, một chỗ, và mẫu seed đổi theo. Seeder chỉ thêm, nên đổi tên KHÔNG tới được mẫu hay vụ
     * việc đã có trên máy chủ.
     *
     * @return array{0: string, 1: bool, 2: string}
     */
    private static function serviceContractItem(): array
    {
        return [BillingRelationManager::REQUIRED_CHECKLIST_ITEM_NAME, true, 'Văn phòng soạn, anh/chị ký.'];
    }

    /** @return array{0: string, 1: bool, 2: string} */
    private function identityItem(string $description): array
    {
        return ['Giấy tờ tuỳ thân, bản sao chứng thực', true, $description];
    }

    /**
     * **Chỉ THÊM, không bao giờ sửa, không bao giờ thay chỗ (final review X10, C-I5; rà soát cuối
     * làn M9, C1).** Một danh mục mẫu (kèm đầu mục) chỉ được tạo khi loại vụ việc đó CHƯA có danh
     * mục mẫu NÀO, bất kể tên, kể cả đã xoá mềm. Chỉ so tên là chưa đủ: `OpenMatter` áp mẫu đang
     * dùng MỚI NHẤT (id lớn nhất) của loại, nên một mẫu seed chèn thêm vào loại mà văn phòng đã tự
     * soạn mẫu (ví dụ HS/DN/LD, hồi chưa có mẫu seed; hay một loại HC/TM/NH/SH/TC/XD văn phòng tự
     * tạo) sẽ lặng lẽ thay danh mục của văn phòng ở mọi vụ mở sau đó. Loại đã có mẫu thì bỏ qua
     * hoàn toàn: đầu mục đã xoá không quay lại, mô tả/bắt buộc đã sửa giữ nguyên, danh mục đã tắt
     * vẫn tắt, mẫu văn phòng đã xoá không được thay bằng mẫu seed. Loại vụ việc không còn (đã xoá
     * mềm hay chưa từng có) thì không có gì để gắn danh mục vào.
     *
     * @param  list<array{0: string, 1: bool, 2: string}>  $items
     */
    private function template(string $typeCode, string $name, array $items): void
    {
        $type = MatterType::query()->where('code', $typeCode)->first();

        if ($type === null) {
            return;
        }

        $hasAnyTemplate = ChecklistTemplate::withTrashed()
            ->where('matter_type_id', $type->id)
            ->exists();

        if ($hasAnyTemplate) {
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
