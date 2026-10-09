<?php

namespace App\Filament\Admin\Resources\Matters\Actions;

use App\Actions\Mcp\UseStageLogDraft;
use App\Models\Matter;
use App\Models\StageLogDraft;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * "Mở nháp" trên khối "Nháp từ AI (n)" của tab Tiến độ (M11 Task 12): CHÍNH form "Thêm cập nhật"
 * ({@see AddUpdateAction} — cùng schema, cùng bản xem trước "đúng như khách sẽ thấy", cùng công tắc
 * công bố theo luật SPEC §7.3), điền sẵn nội dung của một nháp do AI soạn. Bấm lưu đi qua
 * {@see UseStageLogDraft}: vẫn `TransitionMatterStage` dưới tên người bấm, cộng việc đánh dấu nháp đã
 * dùng trong cùng transaction.
 *
 * Nháp đến từ ĐỐI SỐ `draft` (id) của lần mount, không từ một component gắn sẵn: nút được vẽ bằng
 * `$livewire->getAction('useStageLogDraft', isMounting: false)(['draft' => id])` trong khối nháp
 * (`ai-drafts.blade.php`), và action vẫn giải được khi
 * nháp vừa bị người khác dùng — để lần bấm đó nhận câu "đã dùng hoặc đã bỏ" thay vì im lặng không
 * làm gì. Ba cổng, không cổng nào tin đối số:
 *  - `visible()`: nháp có id đó thuộc ĐÚNG vụ của trang và người xem có `transitionStage` (một id
 *    của vụ khác ẩn nút, nên Filament không chạy action);
 *  - `beforeFormFilled()`: nháp đã dùng hay đã bỏ thì báo và không mở form;
 *  - `UseStageLogDraft`: hỏi lại tất cả dưới khoá.
 *
 * Mọi closure đọc nháp từ `Action $action` Filament TIÊM vào, không từ `$this` (vòng sửa 1, C1 của
 * rà soát Task 12): `$cached([...])` trả một BẢN SAO mang đối số, còn `$this` trong closure vẫn là
 * bản action dùng chung lúc `setUp()` chạy — không có đối số, nên `visible()` đọc qua `$this` luôn
 * thấy "không có nháp" và nút "Mở nháp" không bao giờ được vẽ. Lúc mount, Filament gộp đối số vào
 * chính bản dùng chung và tiêm nó, nên cùng một cách đọc đúng cho cả lúc vẽ lẫn lúc chạy.
 */
class UseStageLogDraftAction extends AddUpdateAction
{
    public static function getDefaultName(): ?string
    {
        return 'useStageLogDraft';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('ai_drafts.actions.open'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->size(Size::Small)
            ->modalHeading(__('ai_drafts.stage_log.open_heading'))
            ->successNotificationTitle(__('ai_drafts.stage_log.open_success'))
            ->visible(fn (Action $action, RelationManager $livewire): bool => static::draftOf($action, $livewire->getOwnerRecord()) !== null
                && Gate::allows('transitionStage', $livewire->getOwnerRecord()))
            ->beforeFormFilled(function (Action $action, RelationManager $livewire): void {
                if (! static::draftOf($action, $livewire->getOwnerRecord())?->isPending()) {
                    Notification::make()->title(__('ai_drafts.not_pending'))->warning()->send();

                    $action->cancel();
                }
            })
            ->fillForm(fn (Action $action, RelationManager $livewire): array => $this->draftFormData(
                static::draftOf($action, $livewire->getOwnerRecord()),
                $livewire->getOwnerRecord(),
            ));
    }

    /** {@see self::draft()} của chính bản action Filament tiêm vào closure — xem docblock lớp. */
    protected static function draftOf(Action $action, Matter $matter): ?StageLogDraft
    {
        return $action instanceof self ? $action->draft($matter) : null;
    }

    /** Nháp mang id của đối số `draft` của BẢN action này, CHỈ khi nó thuộc vụ `$matter` (đang chờ hay không). */
    public function draft(Matter $matter): ?StageLogDraft
    {
        $id = $this->getArguments()['draft'] ?? null;

        if (! (is_int($id) || (is_string($id) && ctype_digit($id))) || (int) $id <= 0) {
            return null;
        }

        return StageLogDraft::query()->whereKey((int) $id)->where('matter_id', $matter->getKey())->first();
    }

    /**
     * Form "Thêm cập nhật" điền từ nháp. `fillForm()` thay hẳn mặc định của các ô, nên ba ô nháp không
     * có (ngày xảy ra, công tắc công bố) và hai ô nháp có thể để trống (nội dung công bố, ngày dự
     * kiến) nhận đúng mặc định của "Thêm cập nhật": hôm nay, `is_published_to_portal` của vụ, mẫu của
     * giai đoạn, và `default_next_update_days` của giai đoạn.
     */
    protected function draftFormData(?StageLogDraft $draft, Matter $matter): array
    {
        return [
            'occurred_at' => today(),
            'internal_note' => $draft?->internal_note,
            'public_content' => $draft?->public_content ?? $this->stageTemplate($matter, $matter->stage),
            'next_step' => $draft?->next_step,
            'client_action' => $draft?->client_action,
            'expected_next_update_at' => $draft?->expected_next_update_at?->toDateString()
                ?? $this->stageDefaultNextUpdateAt($matter, $matter->stage),
            'publish' => (bool) $matter->is_published_to_portal,
        ];
    }

    /**
     * Lần ghi đi qua {@see UseStageLogDraft}. `DomainException` (nháp vừa bị dùng hay bỏ, giai đoạn đã
     * trôi, vụ chưa bật portal) đi về `setUpStageUpdateAction()` như mọi lần "Thêm cập nhật"; lời từ
     * chối vì quyền thành cùng loại thông báo, giữ modal mở. `setUpStageUpdateAction()` gọi hàm này
     * trên bản action Filament tiêm vào, nên `$this->draft()` ở đây đọc đúng đối số của lần mount.
     *
     * Nháp không tìm thấy (id lạ, nháp của vụ khác) cho cùng câu `ai_drafts.unavailable` với đường trả
     * lời (`ClientRequestsRelationManager`), không câu "đã dùng hoặc đã bỏ" — rà soát Task 12 m5. Hôm
     * nay nhánh này không tới được: `visible()` ẩn nút khi không có nháp, và Filament hỏi lại
     * `visible()` trước khi chạy action; nó ở đây để câu trả lời vẫn đúng nếu một ngày cổng đó đổi.
     */
    protected function submitStageUpdate(Matter $matter, array $data): void
    {
        try {
            $draft = $this->draft($matter) ?? throw new AuthorizationException(__('ai_drafts.unavailable'));

            app(UseStageLogDraft::class)->handle(
                draft: $draft,
                matter: $matter,
                actor: Auth::user(),
                occurredAt: $data['occurred_at'],
                internalNote: $data['internal_note'] ?? null,
                publicContent: $data['public_content'] ?? null,
                nextStep: $data['next_step'] ?? null,
                clientAction: $data['client_action'] ?? null,
                expectedNextUpdateAt: $this->expectedNextUpdateAtInput($data),
                publish: (bool) ($data['publish'] ?? false),
            );
        } catch (AuthorizationException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            $this->halt();
        }
    }
}
