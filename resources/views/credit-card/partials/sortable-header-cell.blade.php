{{--
    Ô header có thể SẮP XẾP của hai bảng báo cáo (theo thẻ / theo danh mục).

    MỘT nguồn duy nhất cho tiêu đề sort cả hai bảng nên header/body không lệch cột:
      - nhấn tiêu chí đang active → đảo chiều (asc ⇄ desc);
      - nhấn tiêu chí mới → mặc định asc;
      - trạng thái nổi bật qua `aria-sort` + mũi tên ▲/▼ (inactive hiển thị ⇅);
      - href kèm sẵn `period` + `sort`/`dir` nên đổi kỳ không làm mất sort state;
      - chỉ đổi THỨ TỰ dòng, không động vào số liệu/tổng (dòng tổng không có header này).

    Cần truyền: `$label`, `$key` (spend|cashback|percent), `$prefix` (testid),
    tuỳ chọn `$right`/`$tight`/`$rowspan`. `$report`/`$periodKey`/`$sortKey`/`$sortDir`
    có sẵn trong scope trang kết quả.
--}}
@php
    $active = $sortKey === $key;
    $nextDir = $active
        ? ($sortDir === 'desc' ? 'asc' : 'desc')
        : 'asc';
    $icon = ! $active ? '⇅' : ($sortDir === 'desc' ? '▼' : '▲');
    $ariaSort = ! $active ? 'none' : ($sortDir === 'desc' ? 'descending' : 'ascending');
    $space = ($tight ?? false) ? 'px-3' : 'px-4 sm:px-5';
@endphp
<th data-testid="{{ $prefix }}-col-{{ $key }}"
    @if ($rowspan ?? null) rowspan="{{ $rowspan }}" @endif
    aria-sort="{{ $ariaSort }}"
    class="{{ $space }} py-2.5 font-semibold whitespace-nowrap {{ ($right ?? false) ? 'text-right ' : '' }}{{ ($tight ?? false) ? 'align-bottom border-l border-gray-100' : '' }}">
    <a href="{{ route('credit-cards.reports.show', ['report' => $report->id, 'period' => $periodKey, 'sort' => $key, 'dir' => $nextDir]) }}"
       data-testid="{{ $prefix }}-sort-{{ $key }}"
       class="inline-flex items-center gap-1 {{ $active ? 'text-gray-700' : 'text-gray-400 hover:text-gray-600' }}">
        <span>{{ $label }}</span>
        <span aria-hidden="true" class="text-[10px] leading-none {{ $active ? 'text-emerald-700' : '' }}">{{ $icon }}</span>
    </a>
</th>