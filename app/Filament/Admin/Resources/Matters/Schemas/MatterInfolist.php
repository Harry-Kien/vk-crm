<?php

namespace App\Filament\Admin\Resources\Matters\Schemas;

use App\Actions\Matter\RequestHandoverPackage;
use App\Enums\HandoverPackageStatus;
use App\Enums\MatterAiAccess;
use App\Enums\MatterRole;
use App\Models\Matter;
use App\Models\MatterArchive;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

/**
 * Tab "Tổng quan" (SPEC §7.2): thông tin vụ việc và đội ngũ. Công tắc công bố portal KHÔNG nằm
 * trong infolist này (infolist chỉ đọc, không tương tác) — nó là một page header action trên
 * ViewMatter, gated bởi matter.update (xem docblock ở đó).
 */
class MatterInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('matters.overview_sections.details'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('code')->label(__('matters.fields.code')),
                        TextEntry::make('client.name')->label(__('matters.fields.client')),
                        TextEntry::make('matterType.name')->label(__('matters.fields.matter_type')),
                        TextEntry::make('leadLawyer.name')->label(__('matters.fields.lead_lawyer')),
                        TextEntry::make('title')->label(__('matters.fields.title'))->columnSpanFull(),
                        TextEntry::make('summary_for_client')
                            ->label(__('matters.fields.summary_for_client'))
                            ->columnSpanFull(),
                        // Làn fm A3: "Ghi chú nội bộ" nhập lúc mở vụ (hoặc điền sẵn từ tiếp nhận) —
                        // trước đây không màn hình nào đọc lại. Ai mở được tab này đã qua
                        // `MatterPolicy::view`; khách không bao giờ thấy (cột nằm trong
                        // `Matter::internalAttributes()`). Nền xám và nhãn "Chỉ nội bộ" như ghi chú
                        // nội bộ của tab Tiến độ, bằng `style=` (không có bước dựng CSS).
                        TextEntry::make('description_internal')
                            ->label(__('matters.transition_form.internal_note'))
                            ->hint(__('lifecycle.details.internal_only'))
                            ->hintColor('gray')
                            ->placeholder('—')
                            ->formatStateUsing(fn (?string $state): HtmlString => new HtmlString(sprintf(
                                '<div style="border-radius:0.375rem;padding:0.5rem;white-space:pre-line;'
                                .'background-color:color-mix(in srgb, var(--gray-500) 18%%, transparent)">%s</div>',
                                e((string) $state),
                            )))
                            ->columnSpanFull(),
                        TextEntry::make('stage')
                            ->label(__('matters.fields.stage'))
                            ->badge()
                            ->formatStateUsing(fn (Matter $record): string => $record->currentStage()?->label ?? $record->stage),
                        TextEntry::make('confidentiality')
                            ->label(__('matters.overview_fields.confidentiality'))
                            ->formatStateUsing(fn (Matter $record): string => $record->confidentiality->label()),
                        // M11 R9: cờ "vụ việc lên AI". Đổi bằng nút "Bật/Tắt truy cập qua AI" trên
                        // thanh tiêu đề (ViewMatter::aiAccessAction()), kèm ô tích đồng ý.
                        TextEntry::make('ai_access')
                            ->label(__('matters.overview_fields.ai_access'))
                            ->badge()
                            ->color(fn (Matter $record): string => $record->ai_access === MatterAiAccess::Allowed ? 'warning' : 'gray')
                            ->formatStateUsing(fn (Matter $record): string => $record->ai_access->label()),
                        TextEntry::make('court_name')
                            ->label(__('matters.overview_fields.court_name'))
                            ->placeholder('—'),
                        TextEntry::make('case_number')
                            ->label(__('matters.overview_fields.case_number'))
                            ->placeholder('—'),
                        TextEntry::make('opened_at')
                            ->label(__('matters.overview_fields.opened_at'))
                            ->date('d/m/Y'),
                        TextEntry::make('closed_at')
                            ->label(__('matters.overview_fields.closed_at'))
                            ->date('d/m/Y')
                            ->placeholder('—'),
                        IconEntry::make('is_published_to_portal')
                            ->label(__('matters.fields.is_published_to_portal'))
                            ->boolean(),
                        TextEntry::make('last_client_update_at')
                            ->label(__('matters.fields.last_client_update_at'))
                            ->since()
                            ->placeholder('—'),
                    ]),
                // M7 Task 4: gói bàn giao hồ sơ. Chỉ hiện với vụ ĐÃ CÓ bản ghi lưu trữ (tức đã kết thúc)
                // và chỉ khi người xem xem được bản ghi đó (`MatterArchivePolicy::view` — vụ
                // `restricted` không lộ trạng thái gói cho ai không xem được vụ). Nút sinh/sinh lại
                // nằm ở header của trang (`ViewMatter::generateHandoverAction()`).
                Section::make(__('handover.section.heading'))
                    ->description(__('handover.section.description'))
                    ->columns(2)
                    ->visible(fn (Matter $record): bool => $record->archive !== null
                        && Gate::allows('view', $record->archive))
                    ->schema([
                        TextEntry::make('archive.handover_status')
                            ->label(__('handover.section.fields.status'))
                            ->badge()
                            ->placeholder(__('handover.section.not_requested'))
                            ->formatStateUsing(fn (?HandoverPackageStatus $state): string => $state?->label()
                                ?? __('handover.section.not_requested'))
                            ->color(fn (?HandoverPackageStatus $state): string => match ($state) {
                                HandoverPackageStatus::Generating => 'warning',
                                HandoverPackageStatus::Ready => 'success',
                                HandoverPackageStatus::Failed => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('archive.handover_requested_at')
                            ->label(__('handover.section.fields.requested_at'))
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('archive.handoverRequester.name')
                            ->label(__('handover.section.fields.requested_by'))
                            // "Tự động" CHỈ khi lần yêu cầu thật sự không có người bấm
                            // (`handover_requested_by` NULL). Quan hệ rỗng vì người bấm đã bị xoá
                            // mềm thì là "—", không phải "tự động".
                            ->placeholder(fn (Matter $record): string => $record->archive?->handover_requested_at !== null
                                && $record->archive->handover_requested_by === null
                                ? __('handover.section.automatic')
                                : '—'),
                        TextEntry::make('archive.handover_generated_at')
                            ->label(__('handover.section.fields.generated_at'))
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('archive.handoverDocument.title')
                            ->label(__('handover.section.fields.document'))
                            ->formatStateUsing(fn (Matter $record): string => __('handover.section.document_value', [
                                'title' => $record->archive?->handoverDocument?->title,
                                'version' => $record->archive?->handoverDocument?->version,
                            ]))
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('archive.handover_error')
                            ->label(__('handover.section.fields.error'))
                            ->color('danger')
                            ->visible(fn (Matter $record): bool => $record->archive?->handover_status === HandoverPackageStatus::Failed)
                            ->columnSpanFull(),
                        TextEntry::make('archive.handover_stuck_hint')
                            ->label('')
                            ->state(fn (): string => __('handover.section.stuck_hint'))
                            ->color('warning')
                            ->visible(fn (Matter $record): bool => $record->archive instanceof MatterArchive
                                && RequestHandoverPackage::isStuck($record->archive))
                            ->columnSpanFull(),
                    ]),
                // M7 Task 6 (R5): hạn lưu trữ và quyết định tiêu huỷ (nếu đã ghi). Cùng điều kiện
                // hiển thị với khối gói bàn giao ở trên (có bản ghi lưu trữ, người xem xem được nó).
                // Nút "Ghi quyết định tiêu huỷ" nằm ở header của trang
                // (`ViewMatter::recordDestructionAction()`), chỉ admin thấy.
                Section::make(__('archive.section.heading'))
                    ->description(__('archive.section.description'))
                    ->columns(2)
                    ->visible(fn (Matter $record): bool => $record->archive !== null
                        && Gate::allows('view', $record->archive))
                    ->schema([
                        TextEntry::make('archive.retention_until')
                            ->label(__('archive.section.fields.retention_until'))
                            ->date('d/m/Y')
                            ->placeholder('—'),
                        TextEntry::make('archive.destroyed_at')
                            ->label(__('archive.section.fields.destroyed_at'))
                            ->dateTime('d/m/Y H:i')
                            ->placeholder(__('archive.section.not_destroyed')),
                        TextEntry::make('archive.retention_expired_hint')
                            ->label('')
                            ->state(fn (): string => __('archive.section.retention_expired_hint'))
                            ->color('warning')
                            ->visible(fn (Matter $record): bool => $record->archive instanceof MatterArchive
                                && $record->archive->destroyed_at === null
                                && $record->archive->isRetentionExpired())
                            ->columnSpanFull(),
                        TextEntry::make('archive.destroyer.name')
                            ->label(__('archive.section.fields.destroyed_by'))
                            ->placeholder('—')
                            ->visible(fn (Matter $record): bool => $record->archive?->destroyed_at !== null),
                        TextEntry::make('archive.destruction_record_no')
                            ->label(__('archive.section.fields.destruction_record_no'))
                            ->visible(fn (Matter $record): bool => $record->archive?->destroyed_at !== null),
                        TextEntry::make('archive.destruction_reason')
                            ->label(__('archive.section.fields.destruction_reason'))
                            ->visible(fn (Matter $record): bool => $record->archive?->destroyed_at !== null)
                            ->columnSpanFull(),
                    ]),
                Section::make(__('matters.overview_sections.team'))
                    ->schema([
                        // `hiddenLabel()` chỉ GIẤU nhãn khỏi mắt, không xoá nó: Filament 5 vẫn in
                        // nhãn ra DOM với lớp `fi-sr-only` cho trình đọc màn hình. Không đặt
                        // `label()` thì nhãn đó là tên thuộc tính tự suy ra — "Team", "Name",
                        // "Role in matter" — tức trang này ĐANG đọc tiếng Anh cho người khiếm thị
                        // giữa một phần mềm tiếng Việt, ngay màn hình hiện ra sau mỗi lần mở vụ
                        // việc. Vẫn giữ `hiddenLabel()` vì bố cục hai cột tự nói lên nội dung.
                        RepeatableEntry::make('team')
                            ->label(__('matters.team_fields.members'))
                            ->hiddenLabel()
                            ->columns(2)
                            ->schema([
                                TextEntry::make('name')
                                    ->label(__('matters.team_fields.name'))
                                    ->hiddenLabel(),
                                TextEntry::make('pivot.role_in_matter')
                                    ->label(__('matters.team_fields.role_in_matter'))
                                    ->hiddenLabel()
                                    ->badge()
                                    ->formatStateUsing(fn (MatterRole $state): string => $state->label()),
                            ]),
                    ]),
            ]);
    }
}
