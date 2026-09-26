<?php

namespace App\Filament\Admin\Resources\Matters\Pages;

use App\Actions\Matter\CancelMatter;
use App\Actions\Matter\UpdateMatterDetails;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\Schemas\MatterEditForm;
use App\Models\Matter;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Sửa vụ việc" (SPEC §4.6, §5; M6.5 Task 5, findings `intake/intake-06`, `spec-gap/spec-gap-06`).
 * Trang này KHÔNG có nghiệp vụ riêng — nó thu form (`MatterEditForm`), gọi
 * `App\Actions\Matter\UpdateMatterDetails`, và dịch ngoại lệ của Action thành lỗi form. Cùng hình
 * dạng với `CreateMatter`, đúng CLAUDE.md ("Filament resource... chỉ gọi Action").
 *
 * # Header action "Huỷ hồ sơ mở nhầm" đứng ở ĐÂY, không ở `ViewMatter`
 *
 * Vụ gắn nhầm khách hàng hay nhầm loại vụ việc chỉ sửa được bằng cách huỷ (xem docblock
 * `UpdateMatterDetails` và `CancelMatter` cho lý do hai cột đó bất biến), nên đây đúng là màn
 * hình người dùng đang đứng khi nhận ra mình cần huỷ — không phải một nút rời rạc ở trang xem.
 * Tên action `cancelMatter` khớp đúng tên phương thức `MatterPolicy::cancelMatter()`
 * (`HeaderActionsAreReachableTest`).
 */
class EditMatter extends EditRecord
{
    protected static string $resource = MatterResource::class;

    public function form(Schema $schema): Schema
    {
        return MatterEditForm::configure($schema);
    }

    /**
     * Fix round 1, minor: không kế thừa bảy tab quan hệ của `MatterResource::getRelations()`.
     * Filament mặc định gắn TOÀN BỘ danh sách quan hệ của resource vào MỌI trang của nó — kể cả
     * một trang Edit chỉ có việc sửa năm trường (xem docblock lớp). Không ghi đè hàm này, trang
     * "Sửa vụ việc" sẽ lặp lại nguyên bảy tab của `ViewMatter` (Đội ngũ, Tiến độ, Danh mục hồ sơ,
     * Tài liệu, Các bên, Yêu cầu từ khách, Mốc thời hạn) bên dưới form — một bản sao vô nghĩa của
     * trang Xem, không phải một chức năng của trang Sửa.
     */
    public function getRelationManagers(): array
    {
        return [];
    }

    /**
     * `Action::make('cancelMatter')` tự hỏi Gate trong `visible()` VÀ lặp lại trong `action()`
     * — cùng thành ngữ "mọi admin action phải tự kiểm tra policy" của dự án (xem
     * `EditClientUser::getHeaderActions()` cho cùng lý lẽ về vì sao lặp lại).
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancelMatter')
                ->label(__('matters.actions.cancel_matter'))
                ->color('danger')
                ->visible(fn (): bool => Gate::allows('cancelMatter', $this->record))
                ->schema([
                    Textarea::make('reason')
                        ->label(__('matters.cancel_form.reason'))
                        ->helperText(__('matters.cancel_form.reason_help'))
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    Gate::authorize('cancelMatter', $this->record);

                    $actor = Auth::user();
                    abort_unless($actor instanceof User, 403);

                    /** @var Matter $record */
                    $record = $this->record;

                    try {
                        app(CancelMatter::class)->handle($record, $actor, $data['reason'] ?? '');
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages([
                            $this->cancelActionErrorKey('reason') => $exception->errors()['reason'] ?? [__('actions.unauthorized')],
                        ]);
                    }

                    Notification::make()
                        ->title(__('matters.cancel_form.success'))
                        ->success()
                        ->send();

                    $this->redirect(MatterResource::getUrl('index', panel: 'admin'));
                }),
        ];
    }

    /**
     * Dịch khoá lỗi trần của hộp thoại "Huỷ hồ sơ" sang state path THẬT của action đang mount —
     * cùng công thức `CreateMatter::errorKey()`/`PartiesRelationManager::errorKey()`: một khoá
     * trần không ứng với state path nào thì Filament không hiện lỗi ở đâu cả.
     */
    private function cancelActionErrorKey(string $field): string
    {
        $schemaName = $this->getMountedActionSchemaName();
        $statePath = $schemaName !== null ? $this->getSchema($schemaName)?->getStatePath() : null;

        return filled($statePath) ? "{$statePath}.{$field}" : $field;
    }

    /**
     * Cổng thật: `Gate::authorize('update', ...)` chạy TRONG Action (`UpdateMatterDetails`).
     * `confidentiality` và `summary_for_client` cũng vậy — Action tự ném `ValidationException`
     * gắn ĐÚNG tên trường ngay tại nơi phát hiện lỗi (xem docblock Action), nên trang này KHÔNG
     * còn phải ĐOÁN trường nào gây lỗi (fix round 1, finding minor "EditMatter.php:119": bản đầu
     * bắt MỌI `AuthorizationException` rồi gán cứng vào ô `confidentiality` — sai ngay khi Action
     * thêm một cổng thứ hai cho `summary_for_client`). Việc DUY NHẤT còn lại ở đây là dịch khoá
     * TRẦN của Action (`confidentiality`, `summary_for_client`) sang state path THẬT của form
     * đang mount (`data.<trường>`) — cùng công thức `CreateMatter::errorKey()`.
     *
     * **Một `AuthorizationException` (gate `update` bị thu hồi giữa lúc mở trang và lúc lưu) KHÔNG
     * còn bị bắt ở đây.** Nó không ứng với một ô nào trên form này để gắn vào, nên "map only the
     * confidentiality refusal" (fix round 1) đọc ngược lại thành "đừng map nó vào bất cứ ô nào cả"
     * — để nó tự trôi lên xử lý mặc định của framework (câu chữ và mã trạng thái của chính nó),
     * đúng nghĩa "để một lần thu hồi quyền `update` tự nói bằng câu của nó", không bị ép mượn câu
     * của `confidentiality`.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Matter $record */
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        try {
            return app(UpdateMatterDetails::class)->handle($record, $actor, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())
                    ->mapWithKeys(fn (array $messages, string $field): array => [$this->errorKey($field) => $messages])
                    ->all()
            );
        }
    }

    private function errorKey(string $field): string
    {
        $statePath = $this->getSchema('form')?->getStatePath();

        return filled($statePath) ? "{$statePath}.{$field}" : $field;
    }
}
