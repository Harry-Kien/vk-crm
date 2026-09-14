<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentDownload>
 */
class DocumentDownloadFactory extends Factory
{
    protected $model = DocumentDownload::class;

    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'downloader_type' => (new User)->getMorphClass(),
            'downloader_id' => User::factory(),
            'ip' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'downloaded_at' => now(),
        ];
    }
}
