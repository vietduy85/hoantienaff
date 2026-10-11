<?php

namespace App\Http\Requests\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\Report;
use App\Models\CreditCard\UserCard;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tạo báo cáo chi tiêu.
 *
 * ---------------------------------------------------------------------------
 * THẺ PHẢI THUỘC CHÍNH USER
 * ---------------------------------------------------------------------------
 * `card_ids.*` dùng `Rule::exists(UserCard::class, ...)` kèm điều kiện
 * `user_id = auth()->id()`. Rule dựng theo class model nên Laravel tra đúng
 * connection `creditcard`; và vì đã kẹp `user_id`, id thẻ của người khác bị từ
 * chối ngay ở tầng validation — không có đường nào lọt xuống service.
 *
 * ---------------------------------------------------------------------------
 * DANH MỤC LOẠI TRỪ CHỈ CHO PHÉP HỆ THỐNG HOẶC CỦA CHÍNH USER
 * ---------------------------------------------------------------------------
 * `excluded_category_ids.*` phải là ID của danh mục hệ thống HOẶC danh mục riêng
 * của chính user (không bắt buộc đang hoạt động, vì báo cáo cũ vẫn có thể đang
 * loại trừ một danh mục đã bị ẩn — cần giữ nguyên để user gỡ bỏ khi sửa). ID
 * danh mục riêng của người khác hoặc ID không tồn tại bị từ chối ở đây; tầng
 * service lọc lại lần nữa làm lưới phòng thủ thứ hai.
 *
 * Không có trường `user_id` trong rules: chủ sở hữu LUÔN lấy từ `auth()`.
 */
class StoreReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $userId = (int) $this->user()->id;

        return [
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(Report::types())],
            'card_ids' => ['required', 'array', 'min:1'],
            'card_ids.*' => [
                'integer',
                Rule::exists(UserCard::class, 'id')->where('user_id', $userId),
            ],
            'excluded_category_ids' => ['sometimes', 'array'],
            'excluded_category_ids.*' => [
                'integer',
                function (string $attribute, mixed $value, Closure $fail) use ($userId): void {
                    $exists = Category::query()
                        ->where(function (Builder $query) use ($userId): void {
                            $query->where('scope', Category::SCOPE_SYSTEM)
                                ->orWhere(function (Builder $user) use ($userId): void {
                                    $user->where('scope', Category::SCOPE_USER)
                                        ->where('owner_user_id', $userId);
                                });
                        })
                        ->whereKey((int) $value)
                        ->exists();

                    if (! $exists) {
                        $fail('Có danh mục loại trừ không hợp lệ hoặc không thuộc tài khoản của bạn.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên báo cáo.',
            'name.max' => 'Tên báo cáo không được vượt quá 150 ký tự.',
            'type.required' => 'Vui lòng chọn kiểu báo cáo.',
            'type.in' => 'Kiểu báo cáo không hợp lệ.',
            'card_ids.required' => 'Vui lòng chọn ít nhất một thẻ.',
            'card_ids.array' => 'Danh sách thẻ không hợp lệ.',
            'card_ids.min' => 'Vui lòng chọn ít nhất một thẻ.',
            'card_ids.*.integer' => 'Danh sách thẻ không hợp lệ.',
            'card_ids.*.exists' => 'Có thẻ không tồn tại hoặc không thuộc tài khoản của bạn.',
            'excluded_category_ids.array' => 'Danh sách danh mục loại trừ không hợp lệ.',
            'excluded_category_ids.*.integer' => 'Danh sách danh mục loại trừ không hợp lệ.',
        ];
    }

    /**
     * Dữ liệu đã chuẩn hoá để đưa vào service.
     *
     * @return array{name: string, type: string, card_ids: array<int, int>, excluded_category_ids: array<int, int>}
     */
    public function payload(): array
    {
        return [
            'name' => trim((string) $this->input('name')),
            'type' => (string) $this->input('type'),
            'card_ids' => array_values(array_unique(array_map(
                'intval',
                (array) $this->input('card_ids', []),
            ))),
            'excluded_category_ids' => array_values(array_unique(array_map(
                'intval',
                (array) $this->input('excluded_category_ids', []),
            ))),
        ];
    }
}
