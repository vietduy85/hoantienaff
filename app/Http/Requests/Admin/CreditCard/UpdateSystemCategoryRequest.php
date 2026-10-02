<?php

namespace App\Http\Requests\Admin\CreditCard;

use App\Models\CreditCard\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Sửa danh mục HỆ THỐNG (tên / slug / mô tả).
 *
 * `category_id` là định danh nên KHÔNG đổi; không tạo danh mục mới; không tạo
 * version policy mới; không snapshot tên. Mọi rule/combo tham chiếu bằng
 * `category_id` nên giữ nguyên.
 *
 * ---------------------------------------------------------------------------
 * SLUG CÓ THỂ SỬA, NHƯNG KHÔNG TỰ ĐỔI THEO TÊN
 * ---------------------------------------------------------------------------
 * Frontend gửi cả `name` và `slug` khi bấm "Lưu". Quy tắc: chỉ đổi slug khi
 * người dùng THỰC SỰ gửi `slug` khác giá trị đang lưu. Đổi tên mà không đổi
 * slug ⇒ slug giữ nguyên, không được tự sinh lại từ tên.
 */
class UpdateSystemCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->hasPermissionTo('credit-cards.manage');
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:150',
                // Nhất quán với `StoreSystemCategoryRequest`: nhận slug viết tự
                // nhiên ("Y Tế & Bệnh viện") rồi chuẩn hoá kebab-case. Ép regex
                // `^[a-z0-9-]+$` ở đây sẽ khiến ô Sửa khác ô Thêm: cùng một
                // chuỗi mà Thêm ra `y-te-benh-vien` thì Sửa lại báo lỗi.
                function (string $attribute, $value, $fail): void {
                    $slug = Str::slug((string) $value);

                    if ($slug === '') {
                        $fail('Slug không hợp lệ.');

                        return;
                    }

                    // Trùng phải bị chặn NGAY bằng message rõ ràng, không đợi tới
                    // lúc insert (lúc đó chỉ nổi lỗi DB 1062 khó hiểu). Bỏ qua
                    // chính bản ghi đang sửa để lưu lại slug cũ vẫn hợp lệ.
                    $exists = Category::query()
                        ->system()
                        ->where('slug', $slug)
                        ->whereKeyNot($this->exceptCategoryId())
                        ->exists();

                    if ($exists) {
                        $fail(sprintf('Slug "%s" đã tồn tại trong danh mục hệ thống.', $slug));
                    }
                },
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên danh mục.',
            'name.max' => 'Tên danh mục không được vượt quá 150 ký tự.',
            'slug.required' => 'Vui lòng nhập slug danh mục.',
            'slug.max' => 'Slug không được vượt quá 150 ký tự.',
            'slug.unique' => 'Slug này đã tồn tại trong danh mục hệ thống.',
            'description.max' => 'Mô tả không được vượt quá 1000 ký tự.',
        ];
    }

    /**
     * Id của chính danh mục đang sửa (route param có thể là model hoặc id).
     */
    private function exceptCategoryId(): int|string|null
    {
        $category = $this->route('category');

        if ($category instanceof Category) {
            return $category->getKey();
        }

        return $category === null ? null : (int) $category;
    }

    /**
     * Chuẩn hoá slug TRƯỚC khi validate: trim + lowercase + kebab-case.
     *
     * Nhờ vậy người dùng gõ "  An Uong  " hay "Y Tế & Bệnh viện" vẫn hợp lệ và ra
     * `an-uong` / `y-te-benh-vien`, giống hệt hành vi của form Thêm.
     *
     * Chuẩn hoá rỗng (ví dụ gõ "!!!") thì CỐ TÌNH KHÔNG merge: để rule closure báo
     * "Slug không hợp lệ." thay vì `required` báo nhầm kiểu "vui lòng nhập slug".
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('slug')) {
            $slug = Str::slug((string) $this->input('slug'));

            if ($slug !== '') {
                $this->merge(['slug' => $slug]);
            }
        }
    }

    /**
     * @return array{name?:string, slug?:string, description?:string|null}
     */
    public function payload(): array
    {
        $payload = [];

        if ($this->has('name')) {
            $payload['name'] = trim((string) $this->input('name'));
        }

        // Chỉ gửi `slug` khi có thật trong request. Không gửi ⇒ service giữ nguyên.
        if ($this->has('slug')) {
            $slug = Str::slug((string) $this->input('slug'));

            if ($slug !== '') {
                $payload['slug'] = $slug;
            }
        }

        if ($this->has('description')) {
            $payload['description'] = $this->input('description');
        }

        return $payload;
    }
}
