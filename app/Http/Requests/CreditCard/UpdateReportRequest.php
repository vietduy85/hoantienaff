<?php

namespace App\Http\Requests\CreditCard;

/**
 * Sửa báo cáo chi tiêu.
 *
 * Cùng bộ rule với {@see StoreReportRequest} — tên, kiểu, danh sách thẻ — nên chỉ
 * kế thừa thay vì lặp lại (lặp lại thì hai form sẽ trôi khỏi nhau).
 */
class UpdateReportRequest extends StoreReportRequest
{
}
