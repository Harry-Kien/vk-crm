<?php

namespace Database\Factories;

use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory(),
            'matter_checklist_item_id' => null,
            'group' => DocumentGroup::Issued,
            'title' => 'Văn bản '.fake()->words(3, true),
            'status' => DocumentStatus::InternalDraft,
            'version' => 1,
            'uploader_type' => (new User)->getMorphClass(),
            'uploader_id' => User::factory(),
            'client_can_view' => false,
            'client_can_download' => false,
            'issued_at' => now()->toDateString(),
        ];
    }

    public function group(DocumentGroup $group): static
    {
        return $this->state(fn () => ['group' => $group]);
    }

    public function uploadedBy(User|ClientUser $uploader): static
    {
        return $this->state(fn () => [
            'uploader_type' => $uploader->getMorphClass(),
            'uploader_id' => $uploader->id,
        ]);
    }

    /** Khách nộp qua portal: nhóm A, đã công bố, khách xem và tải được, gắn vào một đầu mục danh mục của đúng vụ việc. */
    public function pendingReview(): static
    {
        return $this
            ->state(fn () => [
                'group' => DocumentGroup::ClientProvided,
                'status' => DocumentStatus::Published,
                'client_can_view' => true,
                'client_can_download' => true,
                'uploader_type' => (new ClientUser)->getMorphClass(),
                'uploader_id' => ClientUser::factory(),
            ])
            ->afterMaking(function (Document $document): void {
                // Lúc này matter_id đã là id thật, nên đầu mục danh mục thuộc đúng vụ việc.
                $document->matter_checklist_item_id ??= MatterChecklistItem::factory()
                    ->status(ChecklistItemStatus::PendingReview)
                    ->create(['matter_id' => $document->matter_id])
                    ->id;
            });
    }
}
