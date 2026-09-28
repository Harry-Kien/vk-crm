<?php

namespace App\Filament\Admin\Resources\OutboundMessages\Schemas;

use App\Enums\OutboundStatus;
use App\Filament\Admin\Resources\OutboundMessages\Tables\OutboundMessagesTable;
use App\Models\OutboundMessage;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Trang xem một dòng nhật ký thư: hiện NGUYÊN VĂN lý do lỗi (brief Task 13), không `->limit()`
 * như cột `error` của bảng. Không hiện thân thư hay số CCCD — `payload` chỉ có khoá `subject`
 * (xem docblock `OutboundMessagesTable`).
 */
class OutboundMessageInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('outbound.label'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('created_at')
                            ->label(__('outbound.fields.created_at'))
                            ->dateTime('d/m/Y H:i'),
                        TextEntry::make('recipient')
                            ->label(__('outbound.fields.recipient')),
                        TextEntry::make('template')
                            ->label(__('outbound.fields.template'))
                            ->formatStateUsing(fn (string $state): string => OutboundMessagesTable::templateLabel($state)),
                        TextEntry::make('status')
                            ->label(__('outbound.fields.status'))
                            ->badge()
                            ->formatStateUsing(fn (OutboundStatus $state): string => $state->label())
                            ->color(fn (OutboundStatus $state): string => OutboundMessagesTable::statusColor($state)),
                        TextEntry::make('related')
                            ->label(__('outbound.fields.related'))
                            ->state(fn (OutboundMessage $record): string => OutboundMessagesTable::relatedLabel($record))
                            ->url(fn (OutboundMessage $record): ?string => OutboundMessagesTable::relatedUrl($record)),
                        TextEntry::make('sent_at')
                            ->label(__('outbound.fields.sent_at'))
                            ->dateTime('d/m/Y H:i')
                            ->placeholder(__('outbound.fields.none')),
                        TextEntry::make('payload.subject')
                            ->label(__('outbound.fields.subject'))
                            ->placeholder(__('outbound.fields.none'))
                            ->columnSpanFull(),
                        // Nguyên văn, KHÔNG cắt: đây là câu trả lời cho "khách nói không nhận
                        // được thư" (SPEC §4.15) — một lý do bị cắt là một lý do không trả lời
                        // được câu hỏi đó.
                        TextEntry::make('error')
                            ->label(__('outbound.fields.error'))
                            ->placeholder(__('outbound.fields.none'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
