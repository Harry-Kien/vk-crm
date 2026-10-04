<?php

namespace App\Enums;

/**
 * Sáu nguồn mà ô tìm kiếm của admin tìm đồng thời (SPEC §6.13; M7 Task 9). Thứ tự các `case` là
 * thứ tự hiển thị — cả trong câu "đang tìm trong…" lẫn trong dòng "khớp ở…" của mỗi kết quả.
 *
 * Ai được tìm theo nguồn nào nằm ở `App\Actions\Search\SearchMatters::sourcesFor()`, không ở đây.
 */
enum SearchSource: string
{
    case Code = 'code';
    case Title = 'title';
    case ClientName = 'client_name';
    case CaseNumber = 'case_number';
    case PartyName = 'party_name';
    case DocumentTitle = 'document_title';

    public function label(): string
    {
        return __('search.sources.'.$this->value);
    }
}
