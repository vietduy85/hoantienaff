{{--
    Placeholder dùng chung cho các trang module Giai đoạn 2.
    Không thêm comment mô tả nghiệp vụ chưa triển khai.
--}}
@props([
    'title' => '',
    'icon' => '🚧',
    'message' => 'Module này sẽ được triển khai ở giai đoạn tiếp theo.',
])

<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 sm:p-8 text-center">
    <p class="text-3xl sm:text-4xl leading-none" aria-hidden="true">{{ $icon }}</p>
    <h3 class="font-bold text-gray-800 text-lg mt-3">{{ $title }}</h3>
    <p class="text-sm text-gray-500 mt-1.5">{{ $message }}</p>
</div>
