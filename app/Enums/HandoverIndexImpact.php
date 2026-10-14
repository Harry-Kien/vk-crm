<?php

namespace App\Enums;

use App\Actions\Matter\RequestHandoverPackage;
use App\Actions\Matter\RetractStageLog;

/**
 * Rút một dòng tiến độ ({@see RetractStageLog}) làm gì với mục lục (`MUC-LUC.pdf`) của gói bàn giao
 * của vụ (làn fm, vòng sửa 1 của Task 1). Mục lục in mọi dòng ĐANG công bố lúc gói được DỰNG, nên một
 * gói đã dựng trước lần rút vẫn mang dòng đó; giá trị này nói hộp thoại "Rút khỏi cổng" phải báo gì,
 * và cùng một phép phân loại quyết định Action có xếp sinh lại gói không
 * ({@see RequestHandoverPackage::indexImpact()}). Không phải cột CSDL.
 *
 *  - `None`: chưa có gói nào đã dựng (và, trên vụ đang kết thúc, không có lần sinh nào đang chờ — trên
 *    vụ đã mở lại lần sinh đang chờ tự hỏng), hoặc hồ sơ đã tiêu huỷ mà gói không đang công bố (gói
 *    không còn công bố được) — gói sinh sau này không có dòng đó;
 *  - `Regenerated`: vụ đang kết thúc, gói hiện có (hoặc lần sinh đang chờ) chưa ra tới khách — hệ
 *    thống tự xếp sinh lại gói;
 *  - `Released`: gói hiện tại ĐANG công bố cho khách — không tự sinh lại (một đường rút duy nhất, rà
 *    soát cuối M7 I2); người dùng phải rút gói rồi sinh lại;
 *  - `Stale`: có gói chưa công bố nhưng vụ đã mở lại — gói chỉ sinh cho vụ đã kết thúc, nên hệ thống
 *    không tự sinh lại; người dùng sinh lại khi vụ kết thúc, trước khi công bố.
 */
enum HandoverIndexImpact: string
{
    case None = 'none';
    case Regenerated = 'regenerated';
    case Released = 'released';
    case Stale = 'stale';

    public function label(): string
    {
        return __('lifecycle.stage_log.index_impact.'.$this->value);
    }
}
