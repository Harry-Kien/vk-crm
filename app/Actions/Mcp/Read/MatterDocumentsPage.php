<?php

namespace App\Actions\Mcp\Read;

use App\Models\Document;
use App\Models\Matter;

/** Kết quả của {@see ListDocuments}. */
final readonly class MatterDocumentsPage
{
    /**
     * @param  KeysetPage<Document>  $page  chỉ tài liệu nhóm A/B/C
     */
    public function __construct(
        public Matter $matter,
        public KeysetPage $page,
    ) {}
}
