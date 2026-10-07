<?php

namespace App\Filament\Admin\Resources\IntakeRequests\Pages;

use App\Actions\Intake\RecordIntake;
use App\Actions\Intake\RecordPrivacyNotice;
use App\Filament\Admin\Resources\IntakeRequests\Concerns\TranslatesIntakeFailures;
use App\Filament\Admin\Resources\IntakeRequests\IntakeRequestResource;
use App\Filament\Admin\Resources\IntakeRequests\Schemas\IntakeRequestForm;
use App\Models\IntakeRequest;
use App\Models\User;
use Filament\Forms\Components\Checkbox;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * "Ghi một lần liên hệ" (M10 Task 3, R1). Trang này không có nghiệp vụ riêng: nó thu PHẦN DANH TÍNH
 * và gọi `App\Actions\Intake\RecordIntake` — Action đó kiểm tra xung đột NGAY trong lần lưu (lần chạm
 * đầu), dưới khoá `conflict-check`. Không có ô câu chuyện ở đây: chỉ một câu nói ô đó mở sau khi lưu,
 * vì kiểm tra phải đứng TRƯỚC lúc nghe chuyện.
 *
 * **Thông báo xử lý dữ liệu (R7a):** câu thông báo (có phiên bản, `lang/vi/intake.php`) hiện để người
 * nhập đọc cho người gọi, và một ô KHÔNG đánh dấu sẵn. Tích ô thì sau khi ghi nhận, trang gọi
 * `RecordPrivacyNotice` với `true` — Action ghi phiên bản, thời điểm, người ghi nhận. Không tích thì
 * không ghi gì: ô câu chuyện ở trang sửa nói rõ còn thiếu bước đó.
 *
 * **Gợi ý trùng (R4):** `RecordIntake` trả kết quả dò trùng (đã lọc theo quyền người nhập). Trang cất
 * nó vào phiên, theo id bản ghi, CHỈ id và cờ (không tên, không số) — trang sửa đọc một lần rồi xoá
 * (`EditIntakeRequest::mount()`), và dựng lại hàng hiển thị qua `visibleTo()`. Không dò lại ở trang
 * sửa: mỗi lần dò tiêu một lượt của bộ đếm tra khách 20 lần/giờ.
 *
 * Mọi từ chối của Action thành lỗi tiếng Việt trên đúng ô ({@see TranslatesIntakeFailures}). Không
 * bọc trong transaction ngoài (`hasDatabaseTransactions()` false): `RecordIntake` tự quản transaction
 * dưới khoá; một transaction ngoài biến nó thành savepoint.
 */
class CreateIntakeRequest extends CreateRecord
{
    use TranslatesIntakeFailures;

    protected static string $resource = IntakeRequestResource::class;

    /** Khoá phiên của gợi ý trùng một bản ghi vừa tạo — `EditIntakeRequest` đọc rồi xoá. */
    public static function duplicatesSessionKey(int $intakeId): string
    {
        return "intake.duplicates.{$intakeId}";
    }

    public function hasDatabaseTransactions(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            IntakeRequestForm::identitySection(editing: false),
            IntakeRequestForm::partiesSection(editing: false),
            Section::make(__('intake.sections.privacy'))
                ->columnSpanFull()
                ->schema([
                    Text::make(__('intake.privacy_notice.text')),
                    Checkbox::make('privacy_notice')
                        ->label(__('intake.fields.privacy_notice'))
                        ->helperText(__('intake.fields.privacy_notice_help'))
                        ->default(false),
                ]),
            Section::make(__('intake.sections.story'))
                ->columnSpanFull()
                ->schema([Text::make(__('intake.gate.locked_create'))]),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        $result = $this->translatingIntakeFailures(fn () => app(RecordIntake::class)->handle($actor, $data, $data['parties'] ?? []));

        if ((bool) ($data['privacy_notice'] ?? false)) {
            $this->translatingIntakeFailures(fn () => app(RecordPrivacyNotice::class)->handle($actor, $result->intake, true));
        }

        $duplicates = $result->duplicates;

        session()->put(static::duplicatesSessionKey($result->intake->getKey()), [
            'same_identity' => $duplicates->sameIdentity->map(fn (IntakeRequest $intake): int => $intake->getKey())->values()->all(),
            'has_hidden' => $duplicates->hasHiddenSameIdentity,
            'same_name' => $duplicates->sameName->map(fn (IntakeRequest $intake): int => $intake->getKey())->values()->all(),
            'is_client' => $duplicates->isExistingClient,
            'lookup_unavailable' => $duplicates->clientLookupUnavailable,
        ]);

        return $result->intake;
    }

    /** Luôn sang trang làm việc của bản ghi vừa tạo — nơi có kết quả kiểm tra và ô câu chuyện. */
    protected function getRedirectUrl(): string
    {
        /** @var IntakeRequest $record */
        $record = $this->getRecord();

        return IntakeRequestResource::getUrl('edit', ['record' => $record], panel: 'admin');
    }
}
