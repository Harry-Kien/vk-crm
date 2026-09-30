<?php

namespace App\Support;

/**
 * Bộ giai đoạn mẫu dùng cho seeder và factory. Sau khi seed, quản trị viên sửa tự do
 * trong bảng matter_type_stages; class này không được đọc ở runtime nghiệp vụ.
 *
 * **Mọi `client_description` phải ≥ 30 ký tự (`mb_strlen`)** — `stage/stage-08`, M6.5 Task 10.
 * `BuildsStageUpdateSchema` điền mẫu này vào `public_content` khi luật sư chọn giai đoạn đích
 * (`afterStateUpdated`, hoặc lúc "Thêm cập nhật" dùng giai đoạn hiện tại), và công tắc "Công bố
 * cho khách ngay" mặc định BẬT khi vụ việc đã bật portal — kéo `required()+minLength(30)` theo
 * (SPEC §4.8, §6.2 bước 4c) vào NGAY LẦN GỬI ĐẦU TIÊN. Trước bản sửa này, `closed` (mọi loại vụ
 * việc), `first_instance` và `on_hold` của hình sự, `intake`/`collecting_documents` của doanh
 * nghiệp đều ngắn hơn 30 ký tự — chọn đúng các giai đoạn này (mà "closed" đặc biệt phổ biến, vì
 * mọi vụ việc đều đóng lại một lần) khiến chính mẫu do hệ thống gợi ý bị luật của hệ thống chặn.
 * `StagePresetsTest` giữ cho việc này không quay lại, lặp qua cả bộ preset tĩnh lẫn dữ liệu đã
 * seed thật.
 *
 * @phpstan-type Stage array{key: string, label: string, client_label: string, client_description: ?string, is_terminal: bool, allowed_next: list<string>, default_next_update_days: int}
 */
final class StagePresets
{
    /** @return list<Stage> */
    public static function for(string $typeCode): array
    {
        return match ($typeCode) {
            'HS' => self::criminal(),
            'DN' => self::corporate(),
            // Sáu lĩnh vực thêm ở M9 Task 1: PHẢI có nhánh tường minh. `default` bên dưới là bộ tố
            // tụng dân sự đầy đủ (nộp đơn, toà thụ lý, phúc thẩm…); thiếu nhánh này thì sáu mã mới
            // lặng lẽ nhận bộ đó, và một vụ thuế hay ngân hàng có "Toà thụ lý" trong danh sách giai đoạn.
            'HC', 'TM', 'NH', 'SH', 'TC', 'XD' => self::provisional(),
            default => self::civil(),
        };
    }

    /** Tranh chấp dân sự, đất đai, hôn nhân, lao động (SPEC §4.5). @return list<Stage> */
    public static function civil(): array
    {
        return [
            self::stage('intake', 'Tiếp nhận', 'Đã tiếp nhận yêu cầu', 'Văn phòng đã nhận yêu cầu và đang đánh giá vụ việc.', ['collecting_documents', 'on_hold']),
            self::stage('collecting_documents', 'Thu thập hồ sơ', 'Đang thu thập giấy tờ', 'Văn phòng cùng anh/chị chuẩn bị đầy đủ giấy tờ cần thiết.', ['drafting', 'on_hold']),
            self::stage('drafting', 'Soạn đơn', 'Đang soạn đơn khởi kiện', 'Luật sư đang soạn đơn và các tài liệu nộp toà.', ['filed']),
            self::stage('filed', 'Đã nộp đơn', 'Đã nộp đơn cho toà', 'Đơn đã được nộp, đang chờ toà xem xét thụ lý.', ['court_accepted'], 10),
            self::stage('court_accepted', 'Toà thụ lý', 'Toà đã nhận giải quyết', 'Toà án đã thụ lý vụ việc và sẽ tiến hành các bước tiếp theo.', ['mediation', 'first_instance'], 21),
            self::stage('mediation', 'Hoà giải', 'Đang hoà giải', 'Toà tổ chức hoà giải giữa các bên.', ['first_instance', 'closed'], 21),
            self::stage('first_instance', 'Sơ thẩm', 'Đang xét xử sơ thẩm', 'Vụ việc đang được xét xử lần đầu.', ['appeal', 'enforcement', 'closed'], 30),
            self::stage('appeal', 'Phúc thẩm', 'Đang xét xử phúc thẩm', 'Vụ việc được xem xét lại ở cấp cao hơn.', ['enforcement', 'closed'], 30),
            self::stage('enforcement', 'Thi hành án', 'Đang thi hành án', 'Bản án đã có hiệu lực, đang thực hiện thi hành.', ['closed'], 30),
            self::stage('closed', 'Kết thúc', 'Đã kết thúc', 'Vụ việc đã hoàn tất, không còn bước xử lý nào tiếp theo.', [], 14, true),
            self::stage('on_hold', 'Tạm dừng', 'Tạm dừng theo yêu cầu', 'Vụ việc tạm dừng, sẽ tiếp tục khi có đủ điều kiện.', ['intake', 'collecting_documents'], 30),
        ];
    }

    /** Hình sự. @return list<Stage> */
    public static function criminal(): array
    {
        return [
            self::stage('intake', 'Tiếp nhận', 'Đã tiếp nhận yêu cầu', 'Văn phòng đã nhận yêu cầu bào chữa hoặc bảo vệ.', ['investigation', 'on_hold']),
            self::stage('investigation', 'Điều tra', 'Giai đoạn điều tra', 'Cơ quan điều tra đang làm việc, luật sư tham gia bảo vệ quyền lợi.', ['prosecution', 'closed'], 30),
            self::stage('prosecution', 'Truy tố', 'Giai đoạn truy tố', 'Viện kiểm sát đang xem xét hồ sơ.', ['first_instance', 'closed'], 30),
            self::stage('first_instance', 'Sơ thẩm', 'Xét xử sơ thẩm', 'Toà xét xử lần đầu vụ án hình sự này.', ['appeal', 'closed'], 30),
            self::stage('appeal', 'Phúc thẩm', 'Xét xử phúc thẩm', 'Toà cấp trên xem xét lại bản án.', ['closed'], 30),
            self::stage('closed', 'Kết thúc', 'Đã kết thúc', 'Vụ việc hình sự đã hoàn tất, không còn bước xử lý nào tiếp theo.', [], 14, true),
            self::stage('on_hold', 'Tạm dừng', 'Tạm dừng', 'Vụ việc tạm dừng, chưa có mốc tiếp tục cụ thể.', ['intake'], 30),
        ];
    }

    /** Doanh nghiệp: thủ tục hành chính, không qua toà. @return list<Stage> */
    public static function corporate(): array
    {
        return [
            self::stage('intake', 'Tiếp nhận', 'Đã tiếp nhận yêu cầu', 'Văn phòng đã nhận yêu cầu và đang xử lý hồ sơ.', ['collecting_documents']),
            self::stage('collecting_documents', 'Thu thập hồ sơ', 'Đang thu thập giấy tờ', 'Đang chuẩn bị hồ sơ đầy đủ theo đúng quy định.', ['drafting']),
            self::stage('drafting', 'Soạn hồ sơ', 'Đang soạn hồ sơ', 'Luật sư soạn hồ sơ nộp cơ quan đăng ký.', ['submitted']),
            self::stage('submitted', 'Đã nộp', 'Đã nộp cơ quan nhà nước', 'Hồ sơ đã nộp, đang chờ kết quả.', ['completed', 'collecting_documents'], 7),
            self::stage('completed', 'Hoàn tất', 'Đã có kết quả', 'Đã nhận kết quả từ cơ quan nhà nước.', [], 14, true),
        ];
    }

    /**
     * Bộ năm giai đoạn TẠM, dùng chung cho sáu lĩnh vực mới (M9 Task 1): hành chính và giấy phép,
     * hợp đồng và thương mại, ngân hàng và tín dụng, sở hữu trí tuệ và công nghệ, thuế và tài
     * chính, xây dựng và hạ tầng. Quy trình thật của từng lĩnh vực là kiến thức hành nghề mà mã
     * không có; chủ văn phòng sẽ mô tả sau (câu hỏi còn mở của kế hoạch M9). Tới lúc đó quản trị
     * viên sửa các giai đoạn ĐÃ seed của từng loại ở tab "Giai đoạn" của loại vụ việc
     * (`StagesRelationManager`, các dòng `matter_type_stages`), không phải bộ PHP này: `MatterTypeSeeder`
     * chỉ thêm, nên đổi bộ này chỉ tới được một bản cài mới, không tới máy chủ đã seed. Một chuỗi
     * thẳng, không `on_hold`, đúng một giai đoạn kết thúc (`closed`). @return list<Stage>
     */
    public static function provisional(): array
    {
        return [
            self::stage('intake', 'Tiếp nhận', 'Đã tiếp nhận yêu cầu', 'Văn phòng đã nhận yêu cầu và đang đánh giá vụ việc.', ['collecting_documents']),
            self::stage('collecting_documents', 'Thu thập hồ sơ', 'Đang thu thập giấy tờ', 'Văn phòng cùng anh/chị chuẩn bị đầy đủ giấy tờ cần thiết.', ['drafting']),
            self::stage('drafting', 'Soạn hồ sơ', 'Đang soạn hồ sơ', 'Luật sư đang soạn hồ sơ và các văn bản cần thiết cho vụ việc.', ['in_progress']),
            self::stage('in_progress', 'Đang thực hiện', 'Đang thực hiện công việc', 'Văn phòng đang thực hiện các bước công việc đã thống nhất với anh/chị.', ['closed']),
            self::stage('closed', 'Kết thúc', 'Đã kết thúc', 'Vụ việc đã hoàn tất, không còn bước xử lý nào tiếp theo.', [], 14, true),
        ];
    }

    /** @param list<string> $allowedNext @return Stage */
    private static function stage(string $key, string $label, string $clientLabel, ?string $description, array $allowedNext, int $days = 14, bool $terminal = false): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'client_label' => $clientLabel,
            'client_description' => $description,
            'is_terminal' => $terminal,
            'allowed_next' => $allowedNext,
            'default_next_update_days' => $days,
        ];
    }
}
