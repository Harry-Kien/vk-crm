<?php

namespace App\Support\Billing;

/**
 * Ba màu của tiền trên trang Doanh thu — MỘT chỗ cho lát của vành khuyên (`ReceivablesDonutWidget`) và
 * chấm màu của ô số đầu trang (`RevenueKpiWidget`, làn rv): Đã thu xanh lá, Còn phải thu xanh dương,
 * Quá hạn đỏ. Bộ ba đã kiểm chứng máy ở kế hoạch M9 (phân biệt được trên nền sáng lẫn tối).
 *
 * Mã hex, không biến CSS: Chart.js vẽ lên canvas, nơi biến CSS không tới được — và ô số dùng CHÍNH mã
 * đó để hai nơi không lệch nhau một sắc độ.
 */
final class RevenueColours
{
    public const COLLECTED = '#0ca30c';

    public const OUTSTANDING = '#4a73bd';

    public const OVERDUE = '#d03b3b';
}
