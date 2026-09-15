<?php

namespace App\Filament\Admin\Resources\Matters\RelationManagers;

use App\Actions\RunConflictCheck;
use App\Enums\ConflictLevel;
use App\Enums\PartyRole;
use App\Filament\Admin\Concerns\ScopesToVisibleMatters;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Support\ConflictCheckResult;
use App\Support\ConflictMatch;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tab "Các bên" (SPEC §7.2, §4.16): bảng matter_parties. Thêm một bên thì chạy lại
 * RunConflictCheck NGAY (trên chính bên vừa thêm — Action tự nạp các bên đã có của vụ để xét lại
 * cùng lúc, xem docblock RunConflictCheck) và hiện kết quả TẠI CHỖ bằng một Notification — người
 * dùng không phải rời trang hay tải lại gì để thấy kết quả, đúng tinh thần "tại chỗ" của SPEC dù
 * không phải một banner tĩnh trong bảng.
 *
 * Không dùng CreateAction mặc định (```$relationship->create($data)```): MatterParty::fill() cố ý
 * loại id_number_hash/phone_normalized khỏi mass-assignment (chỉ ghi qua identify()), và các
 * trường thô id_number/phone trên form này không phải cột thật — ->using() tự dựng bản ghi qua
 * identify(), giống hệt OpenMatter::buildParty().
 */
class PartiesRelationManager extends RelationManager
{
    use ScopesToVisibleMatters;

    protected static string $relationship = 'parties';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('matters.tabs.parties');
    }

    /**
     * Filament 5 mặc định coi relation manager trên trang ViewRecord là chỉ đọc
     * (`Panel::hasReadOnlyRelationManagersOnResourceViewPagesByDefault()` = true), nên CreateAction
     * bị `Response::deny()` bất kể policy nói gì — phải tắt ở đây để MatterPartyPolicy::create
     * (matter.update) là nơi quyết định duy nhất, đúng yêu cầu "thêm một bên" của SPEC §7.2.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('role')
                    ->label(__('matters.party_fields.role'))
                    ->options(collect(PartyRole::cases())->mapWithKeys(fn (PartyRole $role) => [$role->value => $role->label()]))
                    ->required(),
                Toggle::make('is_our_client')
                    ->label(__('matters.party_fields.is_our_client'))
                    ->live()
                    ->default(false),
                Select::make('client_id')
                    ->label(__('matters.party_fields.client'))
                    ->options(fn (): array => Client::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->visible(fn (Get $get): bool => (bool) $get('is_our_client')),
                TextInput::make('name')
                    ->label(__('matters.party_fields.name'))
                    ->required()
                    ->maxLength(200),
                TextInput::make('id_number')
                    ->label(__('matters.party_fields.id_number'))
                    ->maxLength(20),
                TextInput::make('phone')
                    ->label(__('matters.party_fields.phone'))
                    ->tel()
                    ->maxLength(20),
                TextInput::make('address')
                    ->label(__('matters.party_fields.address'))
                    ->maxLength(300),
                Textarea::make('note')
                    ->label(__('matters.party_fields.note'))
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('role')
                    ->label(__('matters.party_fields.role'))
                    ->badge()
                    ->formatStateUsing(fn (PartyRole $state): string => $state->label()),
                TextColumn::make('name')
                    ->label(__('matters.party_fields.name'))
                    ->searchable(),
                IconColumn::make('is_our_client')
                    ->label(__('matters.party_fields.is_our_client'))
                    ->boolean(),
                TextColumn::make('client.name')
                    ->label(__('matters.party_fields.client'))
                    ->placeholder('—'),
                TextColumn::make('address')
                    ->label(__('matters.party_fields.address'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->using(function (array $data, RelationManager $livewire): MatterParty {
                        /** @var Matter $matter */
                        $matter = $livewire->getOwnerRecord();
                        $isOurClient = (bool) ($data['is_our_client'] ?? false);

                        $party = new MatterParty([
                            'matter_id' => $matter->id,
                            'role' => $data['role'],
                            'is_our_client' => $isOurClient,
                            'client_id' => $isOurClient ? ($data['client_id'] ?? null) : null,
                            'name' => $data['name'],
                            'address' => $data['address'] ?? null,
                            'note' => $data['note'] ?? null,
                        ]);
                        $party->identify($data['id_number'] ?? null, $data['phone'] ?? null);
                        $party->save();

                        $result = app(RunConflictCheck::class)->handle(collect([$party]), $matter);

                        static::notifyConflictCheckResult($result);

                        return $party;
                    }),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => static::scopeToVisibleMatters($query));
    }

    private static function notifyConflictCheckResult(ConflictCheckResult $result): void
    {
        $color = match ($result->level) {
            ConflictLevel::Red => 'danger',
            ConflictLevel::Yellow => 'warning',
            ConflictLevel::Green => 'success',
        };

        $body = $result->matches->isEmpty()
            ? __('matters.parties.conflict_check_clear')
            : $result->matches
                ->map(fn (ConflictMatch $match): string => sprintf(
                    '%s (%s) — %s, %s: %s',
                    $match->matterCode,
                    $match->matterTypeName,
                    $match->partyRole->label(),
                    $match->tier->label(),
                    $match->level->label(),
                ))
                ->implode("\n");

        if ($result->hasIncompleteParties()) {
            $body .= "\n".__('matters.parties.conflict_check_incomplete', ['names' => implode(', ', $result->incompleteParties())]);
        }

        Notification::make()
            ->title(__('matters.parties.conflict_check_title'))
            ->body($body)
            ->color($color)
            ->persistent()
            ->send();
    }
}
