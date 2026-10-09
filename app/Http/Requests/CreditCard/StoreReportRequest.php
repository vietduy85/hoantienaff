<?php

namespace App\Http\Requests\CreditCard;

use App\Models\CreditCard\Report;
use App\Models\CreditCard\UserCard;
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
        ];
    }

    /**
     * Dữ liệu đã chuẩn hoá để đưa vào service.
     *
     * @return array{name: string, type: string, card_ids: array<int, int>}
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
        ];
    }
}
