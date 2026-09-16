<?php

namespace App\Filament\Admin\Resources\Matters\Actions\Concerns;

use App\Actions\TransitionMatterStage;
use App\Exceptions\MatterNotPublishedToPortal;
use App\Models\Matter;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
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

                try {
                    app(TransitionMatterStage::class)->handle(
                        matter: $matter,
                        actor: Auth::user(),
                        toStage: $this->resolveToStage($matter, $data),
                        occurredAt: $data['occurred_at'],
                        internalNote: $data['internal_note'] ?? null,
                        publicContent: $data['public_content'] ?? null,
                        nextStep: $data['next_step'] ?? null,
                        clientAction: $data['client_action'] ?? null,
                        // ?: chứ không phải ??: DatePicker rỗng gửi lên chuỗi rỗng, không phải null —
                        // để lọt qua '' thì StageLog (cast 'date') sẽ ném lỗi phân tích ngày tháng,
                        // thay vì để TransitionMatterStage tự tính lại theo default_next_update_days
                        // như thiết kế.
                        expectedNextUpdateAt: $data['expected_next_update_at'] ?: null,
                        publish: (bool) ($data['publish'] ?? false),
                    );
                } catch (MatterNotPublishedToPortal $exception) {
                    // Lớp phòng thủ thứ hai (fix round 1, finding 2): tắt/disable công tắc "publish"
                    // khi vụ chưa bật portal (publishToggleField()) đã chặn đường chính, nên đây là
                    // lưới an toàn cho một đường vào tương lai nào đó chưa lường trước — không để một
                    // DomainException thoát ra khỏi modal thành lỗi 500 không thân thiện.
                    Notification::make()
                        ->title($exception->getMessage())
                        ->danger()
                        ->send();

                    $this->halt();
                }
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

    /**
     * `$default` là gợi ý mẫu lấy từ `matter_type_stages.client_description` (SPEC §7.3).
     *
     * Fix round 1, finding 1: `TransitionMatterStage::handle()` tự ném `ValidationException` với
     * khoá THÔ (`'public_content'`) khi `publish = true` và nội dung dưới 30 ký tự — nhưng khoá đó
     * không khớp state path đầy đủ của một trường trong action đang mount
     * (`mountedActions.{index}.data.public_content`), nên lỗi không bao giờ hiện lên đúng ô: modal
     * chỉ lặng lẽ rollback, không có gì báo cho luật sư. `required()`/`minLength()` ở đây là validate
     * THẬT của chính schema (không phải exception thủ công), nên Filament tự gắn đúng vào trường —
     * đây là nửa "cho người dùng thấy" của luật; nửa "gác cổng thật" vẫn ở TransitionMatterStage,
     * không đổi.
     */
    protected function publicContentField(?string $default): Textarea
    {
        return Textarea::make('public_content')
            ->label(__('matters.transition_form.public_content'))
            ->live()
            ->default($default)
            ->rows(4)
            ->columnSpanFull()
            ->required(fn (Get $get): bool => (bool) $get('publish'))
            ->minLength(fn (Get $get): ?int => $get('publish') ? 30 : null)
            ->helperText(fn (Get $get): ?string => $get('publish')
                ? __('matters.transition_form.public_content_publish_hint')
                : null);
    }

    protected function nextStepField(): Textarea
    {
        return Textarea::make('next_step')
            ->label(__('matters.transition_form.next_step'))
            ->live()
            ->rows(2)
            ->columnSpanFull();
    }

    protected function clientActionField(): Textarea
    {
        return Textarea::make('client_action')
            ->label(__('matters.transition_form.client_action'))
            ->helperText(__('matters.transition_form.client_action_hint'))
            ->live()
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

    /**
     * Mặc định BẬT khi vụ việc đã bật portal, tắt khi chưa (SPEC §7.3, test bắt buộc). Fix round 1,
     * finding 2: khi vụ CHƯA bật portal, `disabled()` khoá hẳn công tắc thay vì chỉ để mặc định tắt
     * — nếu không luật sư vẫn tự bật được rồi gặp `MatterNotPublishedToPortal` không ai báo trước.
     * Trường bị `disabled()` vẫn dehydrate giá trị mặc định (false) bình thường trong Filament 5
     * (`disabled()` ở tầng schema không tự kéo theo `dehydrated(false)`), nên submit vẫn gửi đúng
     * `publish = false`, không cần xử lý gì thêm ở phía Action.
     */
    protected function publishToggleField(Matter $matter): Toggle
    {
        return Toggle::make('publish')
            ->label(__('matters.transition_form.publish'))
            ->live()
            ->default($matter->is_published_to_portal)
            ->disabled(! $matter->is_published_to_portal)
            ->helperText($matter->is_published_to_portal
                ? null
                : __('matters.transition_form.publish_disabled_hint'));
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

    /**
     * Fix round 1, finding E (minor): công thức `now()->addDays($stage->default_next_update_days)`
     * CỐ Ý trùng với bước 4 của `TransitionMatterStage::handle()` (xem docblock lớp đó) — hàm này
     * chỉ tính để GỢI Ý/prefill trên form, không phải luật; `TransitionMatterStage` mới là nơi tính
     * lại thật sự khi `expected_next_update_at` gửi lên rỗng. Hai bên có thể trôi lệch nếu chỉ một
     * bên đổi công thức — không rút thành helper dùng chung vì `TransitionMatterStage` không nên
     * phụ thuộc ngược vào tầng Filament chỉ vì một phép tính ngày, nhưng nếu công thức đổi thì phải
     * sửa CẢ HAI nơi.
     */
    protected function stageDefaultNextUpdateAt(Matter $matter, ?string $stageKey): ?string
    {
        $stage = $stageKey === null ? null : $matter->matterType->stage($stageKey);

        return $stage === null ? null : now()->addDays($stage->default_next_update_days)->toDateString();
    }
}
