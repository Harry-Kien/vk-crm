<?php

namespace App\Filament\Admin\Resources\Matters\Actions;

use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Actions\Concerns\BuildsStageUpdateSchema;
use App\Models\Matter;
use App\Models\MatterTypeStage;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Nút "Chuyển giai đoạn" (SPEC §7.3, §6.2). Chỉ liệt kê các giai đoạn hợp lệ theo `allowed_next`
 * của giai đoạn hiện tại (`stageOptions()`, tách static để test trực tiếp không cần dựng cả
 * Livewire) — KHÔNG tự thêm giai đoạn hiện tại vào danh sách, đó là việc của `AddUpdateAction`
 * (SPEC §6.3). Chọn xong, `afterStateUpdated` gợi ý mẫu nội dung công bố từ
 * `matter_type_stages.client_description` và dự kiến ngày tin tiếp theo từ
 * `default_next_update_days` của giai đoạn ĐÍCH.
 *
 * Fix round 1, finding 3: đổi giai đoạn KHÔNG được ghi đè nội dung luật sư đã tự gõ. `$old` (giai
 * đoạn được chọn TRƯỚC lần đổi này, do Filament tự truyền vào afterStateUpdated) cho biết gợi ý
 * mẫu ban đầu là gì; chỉ ghi đè `public_content`/`expected_next_update_at` khi giá trị hiện tại
 * còn TRỐNG hoặc vẫn còn NGUYÊN gợi ý của lựa chọn cũ — nếu luật sư đã sửa dù chỉ một ký tự, giá
 * trị đó không còn khớp gợi ý cũ nữa và được giữ nguyên.
 */
class TransitionStageAction extends Action
{
    use BuildsStageUpdateSchema;

    public static function getDefaultName(): ?string
    {
        return 'transitionStage';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('matters.actions.transition_stage'))
            ->icon(Heroicon::OutlinedArrowRight)
            ->color('primary')
            ->modalHeading(__('matters.transition_form.transition_heading'))
            ->setUpStageUpdateAction('matters.transition_form.transition_success');
    }

    /**
     * @return array<string, string> giai đoạn hợp lệ (key => nhãn nội bộ) theo `allowed_next` —
     *                               TRỪ `$actor` là `Role::Admin`, khi đó trả về MỌI giai đoạn
     *                               khác giai đoạn hiện tại (`stage/stage-04`, M6.5 Task 10).
     *
     * **Vì sao admin cần thấy nhiều hơn `allowed_next`.** `TransitionMatterStage::handle()` đã
     * cho `Role::Admin` bỏ qua kiểm tra `allowed_next` từ M3 (SPEC §6.2 bước 1,
     * `bypassed_allowed_next` ghi vào audit) — nhưng quyền đó vô dụng nếu `Select` của form không
     * bao giờ ĐƯA RA lựa chọn nằm ngoài `allowed_next`: `required()` trên trường này khiến
     * Filament tự thêm luật `in:` đúng theo danh sách `options()` trả về, nên gửi một giá trị
     * ngoài danh sách luôn bị chặn ở tầng validate, trước khi `TransitionMatterStage` kịp chạy.
     * Một vụ bấm nhầm sang giai đoạn cuối (`allowed_next = []`, ví dụ 'closed') vì vậy kẹt vĩnh
     * viễn — cách sửa duy nhất từng có là sửa thẳng CSDL, mất luôn dấu vết audit SPEC đòi.
     *
     * **Nhãn cảnh báo, không phải một lựa chọn im lặng.** Giai đoạn nằm NGOÀI `allowed_next` mang
     * thêm hậu tố {@see __('matters.transition_form.outside_allowed_next_suffix')} — admin nhìn
     * ngay biết mình đang đi ngoài luồng thường, không bấm nhầm giữa một chuyển giai đoạn bình
     * thường và một lần bỏ qua có chủ đích.
     *
     * **Giai đoạn HIỆN TẠI không bao giờ là một lựa chọn ở đây, kể cả cho admin** — dòng cùng giai
     * đoạn (SPEC §6.3) là việc của `AddUpdateAction`, một nút riêng; gộp hai việc vào một Select sẽ
     * làm mất phân biệt "đổi giai đoạn" / "chỉ thêm cập nhật" mà hai nút tồn tại để giữ.
     */
    public static function stageOptions(Matter $matter, ?User $actor = null): array
    {
        $current = $matter->currentStage();

        if ($current === null) {
            return [];
        }

        $allowedNext = collect($current->allowed_next);

        if ($actor === null || ! $actor->hasRole(Role::Admin->value)) {
            return $allowedNext
                ->mapWithKeys(fn (string $key): array => [$key => $matter->matterType->stage($key)?->label ?? $key])
                ->all();
        }

        $suffix = ' '.__('matters.transition_form.outside_allowed_next_suffix');

        return $matter->matterType->stages
            ->reject(fn (MatterTypeStage $stage): bool => $stage->key === $current->key)
            ->mapWithKeys(fn (MatterTypeStage $stage): array => [
                $stage->key => $allowedNext->contains($stage->key) ? $stage->label : $stage->label.$suffix,
            ])
            ->all();
    }

    protected function resolveToStage(Matter $matter, array $data): string
    {
        return $data['to_stage'];
    }

    /**
     * Rà soát cuối M7, I3: sau lần chuyển sang `$toStage`, vụ có còn ở trạng thái đóng không — cùng
     * luật `TransitionMatterStage` dùng để ghi `closed_at`: giai đoạn đích `is_terminal` thì vụ đóng
     * (hoặc vẫn đóng, `closed_at` giữ nguyên), không kết thúc thì vụ mở (một vụ đang đóng được MỞ
     * LẠI). Chưa chọn giai đoạn đích thì chưa biết — `false`, câu cảnh báo phụ thuộc nó chưa hiện.
     */
    private function targetKeepsMatterClosed(Matter $matter, ?string $toStage): bool
    {
        return $toStage !== null
            && (bool) $matter->matterType->stage($toStage)?->is_terminal;
    }

    protected function buildSchema(Matter $matter): array
    {
        return [
            Select::make('to_stage')
                ->label(__('matters.transition_form.to_stage'))
                ->options(fn (): array => static::stageOptions(
                    $matter,
                    Auth::user() instanceof User ? Auth::user() : null,
                ))
                ->live()
                ->required()
                ->afterStateUpdated(function (Set $set, Get $get, ?string $state, ?string $old) use ($matter): void {
                    $previousTemplate = $this->stageTemplate($matter, $old);
                    $currentContent = $get('public_content');

                    if (blank($currentContent) || $currentContent === $previousTemplate) {
                        $set('public_content', $this->stageTemplate($matter, $state));
                    }

                    $previousExpectedDate = $this->stageDefaultNextUpdateAt($matter, $old);
                    $currentExpectedDate = $get('expected_next_update_at');

                    if (blank($currentExpectedDate) || $currentExpectedDate === $previousExpectedDate) {
                        $set('expected_next_update_at', $this->stageDefaultNextUpdateAt($matter, $state));
                    }
                }),
            $this->occurredAtField(),
            $this->internalNoteField(),
            $this->publicContentField(null),
            $this->nextStepField(),
            $this->clientActionField(),
            $this->expectedNextUpdateAtField(null),
            $this->publishToggleField($matter),
            $this->noActivatedAccountWarning($matter),
            // Rà soát cuối M7, I3: "vụ không còn trên cổng" chỉ đúng khi giai đoạn đích giữ vụ ở
            // trạng thái đóng — xem docblock `matterNotOnPortalWarning()`.
            $this->matterNotOnPortalWarning($matter, fn (Get $get): bool => $this->targetKeepsMatterClosed($matter, $get('to_stage'))),
            $this->previewField(fn (Get $get): array => [
                'showStageLabel' => true,
                'stageLabel' => $this->stageClientLabel($matter, $get('to_stage')),
                'publicContent' => $get('public_content'),
                'nextStep' => $get('next_step'),
                'clientAction' => $get('client_action'),
                'expectedNextUpdateAt' => $get('expected_next_update_at'),
                'willPublish' => (bool) $get('publish'),
            ]),
        ];
    }
}
