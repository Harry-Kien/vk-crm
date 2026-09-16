<?php

namespace App\Filament\Admin\Resources\Matters\Actions\Concerns;

use App\Actions\TransitionMatterStage;
use App\Models\Matter;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Phần dùng chung của hai Action "Chuyển giai đoạn" và "Thêm cập nhật" (SPEC §7.3, §6.2, §6.3):
 * cả hai đều gọi CÙNG MỘT `TransitionMatterStage` (xem docblock của Action đó — "thêm cập nhật"
 * chỉ là gọi Action với `to_stage` bằng giai đoạn hiện tại), cùng gate theo `matter.transitionStage`,
 * và có cùng bộ trường phụ (ngày xảy ra, ghi chú nội bộ, nội dung công bố, tiếp theo, cần làm gì,
 * dự kiến tin tiếp theo, công tắc công bố, bản xem trước sống). Điểm khác nhau duy nhất giữa hai
 * Action là GIAI ĐOẠN ĐÍCH: `TransitionStageAction` có thêm một `Select` để chọn, `AddUpdateAction`
 * cố định bằng giai đoạn hiện tại — mỗi lớp tự cài `resolveToStage()` và `buildSchema()`.
 */
trait BuildsStageUpdateSchema
{
    /** Nối phần dùng chung vào Action: quyền, khổ modal, schema (do lớp con cung cấp), và submit. */
    protected function setUpStageUpdateAction(string $successMessageKey): void
    {
        $this->size(Size::Large)
            ->modalWidth(Width::FourExtraLarge)
            ->visible(fn (RelationManager $livewire): bool => Gate::allows('transitionStage', $livewire->getOwnerRecord()))
            ->schema(fn (RelationManager $livewire): array => $this->buildSchema($livewire->getOwnerRecord()))
            ->successNotificationTitle(__($successMessageKey))
            ->action(function (array $data, RelationManager $livewire): void {
                /** @var Matter $matter */
                $matter = $livewire->getOwnerRecord();

                app(TransitionMatterStage::class)->handle(
                    matter: $matter,
                    actor: Auth::user(),
                    toStage: $this->resolveToStage($matter, $data),
                    occurredAt: $data['occurred_at'],
                    internalNote: $data['internal_note'] ?? null,
                    publicContent: $data['public_content'] ?? null,
                    nextStep: $data['next_step'] ?? null,
                    clientAction: $data['client_action'] ?? null,
                    // ?: chứ không phải ??: DatePicker rỗng gửi lên chuỗi rỗng, không phải null — để
                    // lọt qua '' thì StageLog (cast 'date') sẽ ném lỗi phân tích ngày tháng, thay vì
                    // để TransitionMatterStage tự tính lại theo default_next_update_days như thiết kế.
                    expectedNextUpdateAt: $data['expected_next_update_at'] ?: null,
                    publish: (bool) ($data['publish'] ?? false),
                );
            });
    }

    /** Giai đoạn đích thật sự gửi cho Action — khác nhau giữa hai lớp con. */
    abstract protected function resolveToStage(Matter $matter, array $data): string;

    /** Toàn bộ schema của modal — khác nhau giữa hai lớp con (có/không có Select to_stage). */
    abstract protected function buildSchema(Matter $matter): array;

    protected function occurredAtField(): DatePicker
    {
        return DatePicker::make('occurred_at')
            ->label(__('matters.transition_form.occurred_at'))
            ->default(today())
            ->native(false)
            ->required();
    }

    protected function internalNoteField(): Textarea
    {
        return Textarea::make('internal_note')
            ->label(__('matters.transition_form.internal_note'))
            ->helperText(__('matters.transition_form.internal_note_hint'))
            ->rows(3)
            ->columnSpanFull();
    }

    /** `$default` là gợi ý mẫu lấy từ `matter_type_stages.client_description` (SPEC §7.3). */
    protected function publicContentField(?string $default): Textarea
    {
        return Textarea::make('public_content')
            ->label(__('matters.transition_form.public_content'))
            ->live(onBlur: true)
            ->default($default)
            ->rows(4)
            ->columnSpanFull();
    }

    protected function nextStepField(): Textarea
    {
        return Textarea::make('next_step')
            ->label(__('matters.transition_form.next_step'))
            ->live(onBlur: true)
            ->rows(2)
            ->columnSpanFull();
    }

    protected function clientActionField(): Textarea
    {
        return Textarea::make('client_action')
            ->label(__('matters.transition_form.client_action'))
            ->helperText(__('matters.transition_form.client_action_hint'))
            ->live(onBlur: true)
            ->rows(2)
            ->columnSpanFull();
    }

    protected function expectedNextUpdateAtField(?string $default): DatePicker
    {
        return DatePicker::make('expected_next_update_at')
            ->label(__('matters.transition_form.expected_next_update_at'))
            ->live()
            ->default($default)
            ->native(false);
    }

    /** Mặc định BẬT khi vụ việc đã bật portal, tắt khi chưa (SPEC §7.3, test bắt buộc). */
    protected function publishToggleField(Matter $matter): Toggle
    {
        return Toggle::make('publish')
            ->label(__('matters.transition_form.publish'))
            ->live()
            ->default($matter->is_published_to_portal);
    }

    /**
     * Bản xem trước đúng như khách sẽ thấy (SPEC §7.3): một `View` component đọc trạng thái các
     * trường `live()` khác qua `$get()` (viewData closure — xem
     * vendor/filament/schemas/docs/09-custom-components.md, "Accessing the state of another
     * component"), KHÔNG bao giờ đọc `internal_note` — đây chính là cơ chế "rẻ nhất" SPEC nói tới:
     * nếu luật sư dán nhầm ghi chú nội bộ vào đúng ô của nó, bản xem trước không đổi; chỉ khi dán
     * (nhầm) vào ô công bố thì mới hiện ra ở đây, và người dùng tự thấy ngay.
     */
    protected function previewField(Closure $viewDataResolver): View
    {
        return View::make('filament.client-preview')
            ->viewData($viewDataResolver)
            ->columnSpanFull();
    }

    protected function stageTemplate(Matter $matter, ?string $stageKey): ?string
    {
        if ($stageKey === null) {
            return null;
        }

        return $matter->matterType->stage($stageKey)?->client_description;
    }

    /** Nhãn giai đoạn DÀNH CHO KHÁCH (`client_label`) — dùng ở bản xem trước, không phải `label` nội bộ. */
    protected function stageClientLabel(Matter $matter, ?string $stageKey): ?string
    {
        if ($stageKey === null) {
            return null;
        }

        return $matter->matterType->stage($stageKey)?->client_label;
    }

    protected function stageDefaultNextUpdateAt(Matter $matter, ?string $stageKey): ?string
    {
        $stage = $stageKey === null ? null : $matter->matterType->stage($stageKey);

        return $stage === null ? null : now()->addDays($stage->default_next_update_days)->toDateString();
    }
}
