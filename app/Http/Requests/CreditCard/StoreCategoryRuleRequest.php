<?php

namespace App\Http\Requests\CreditCard;

use App\Models\CreditCard\Category;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Thêm quy tắc cashback cho một danh mục trong một bậc.
 *
 * ---------------------------------------------------------------------------
 * KHÔNG CÓ TRƯỜNG CASHBACK KẾT QUẢ
 * ---------------------------------------------------------------------------
 * Rule chỉ MÔ TẢ CẤU HÌNH (`cashback_percent` + các mức cap), không mang giá trị
 * cashback đã tính. Vì vậy payload KHÔNG có `cashback_amount`,
 * `calculated_cashback`, `final_cashback` hay bất kỳ cột snapshot nào — những cột
 * đó do `CashbackRecordService` ghi vào `credit_card_transactions`, và tiền phải
 * luôn do hệ thống quyết định, không phải người dùng.
 *
 * Field gửi thừa bị bỏ qua (không whitelist) chứ không gây lỗi, để client cũ
 * không gửi `cashback_amount` cũng không vỡ.
 */
class StoreCategoryRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', $this->categoryRule()],
            'cashback_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'name' => ['nullable', 'string', 'max:150'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'spend_from' => ['sometimes', 'numeric', 'min:0'],
            'spend_to' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max_cashback_per_transaction' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max_cashback_per_category_per_period' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'min_transaction_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'is_enabled' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.required' => 'Vui lòng chọn danh mục.',
            'cashback_percent.required' => 'Vui lòng nhập tỷ lệ hoàn tiền.',
            'cashback_percent.max' => 'Tỷ lệ hoàn tiền không được vượt quá 100%.',
            'spend_from.min' => 'Ngưỡng chi tiêu không được âm.',
        ];
    }

    /**
     * Danh mục phải ĐANG HOẠT ĐỘNG và user được phép dùng.
     *
     * Dùng `Category::query()` (model của connection `creditcard`) chứ không dùng
     * tên bảng thô, và dùng `selectableBy` để không chọn trúng danh mục ẩn hoặc
     * danh mục của người khác. Thông báo lỗi CỐ Ý không phân biệt "không tồn tại"
     * với "không thuộc quyền" — nếu phân biệt thì API trở thành công cụ dò tìm id.
     */
    private function categoryRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_numeric($value)) {
                $fail('Danh mục không hợp lệ.');

                return;
            }

            $exists = Category::query()
                ->selectableBy((int) $this->user()->id)
                ->whereKey((int) $value)
                ->exists();

            if (! $exists) {
                $fail('Danh mục không hợp lệ hoặc bạn không được sử dụng.');
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'category_id' => (int) $this->input('category_id'),
            'cashback_percent' => $this->input('cashback_percent'),
            'name' => $this->input('name'),
            'sort_order' => $this->input('sort_order'),
            'spend_from' => $this->input('spend_from', 0),
            'spend_to' => $this->input('spend_to'),
            'max_cashback_per_transaction' => $this->input('max_cashback_per_transaction'),
            'max_cashback_per_category_per_period' => $this->input('max_cashback_per_category_per_period'),
            'min_transaction_amount' => $this->input('min_transaction_amount'),
            'is_enabled' => $this->input('is_enabled', true),
            'note' => $this->input('note'),
        ];
    }
}
