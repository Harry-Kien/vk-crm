<?php

namespace App\Filament\Admin\Resources\Matters\Schemas;

use App\Enums\MatterRole;
use App\Models\Matter;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

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
                        TextEntry::make('stage')
                            ->label(__('matters.fields.stage'))
                            ->badge()
                            ->formatStateUsing(fn (Matter $record): string => $record->currentStage()?->label ?? $record->stage),
                        TextEntry::make('confidentiality')
                            ->label(__('matters.overview_fields.confidentiality'))
                            ->formatStateUsing(fn (Matter $record): string => $record->confidentiality->label()),
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
