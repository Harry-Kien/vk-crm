<?php

namespace App\Filament\Admin\Resources\Matters\Actions;

use App\Filament\Admin\Resources\Matters\Actions\Concerns\BuildsStageUpdateSchema;
use App\Models\Matter;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;

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

    /** @return array<string, string> giai đoạn hợp lệ (key => nhãn nội bộ) theo `allowed_next`. */
    public static function stageOptions(Matter $matter): array
    {
        $current = $matter->currentStage();

        if ($current === null) {
            return [];
        }

        return collect($current->allowed_next)
            ->mapWithKeys(fn (string $key): array => [$key => $matter->matterType->stage($key)?->label ?? $key])
            ->all();
    }

    protected function resolveToStage(Matter $matter, array $data): string
    {
        return $data['to_stage'];
    }

    protected function buildSchema(Matter $matter): array
    {
        return [
            Select::make('to_stage')
                ->label(__('matters.transition_form.to_stage'))
                ->options(fn (): array => static::stageOptions($matter))
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
            $this->previewField(fn (Get $get): array => [
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
