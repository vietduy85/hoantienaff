<?php

namespace App\Http\Controllers\Admin\CreditCard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreditCard\StorePolicyMccProfileRequest;
use App\Http\Requests\Admin\CreditCard\UpdatePolicyMccProfileRequest;
use App\Models\CreditCard\MccCode;
use App\Models\CreditCard\PolicyMccProfile;
use App\Models\CreditCard\PolicyMccProfileDocument;
use App\Models\CreditCard\PolicyMccProfileRow;
use App\Models\CreditCard\PolicyTemplate;
use App\Services\CreditCard\PolicyMccProfileService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Trang vận hành "Quản lý MCC" — HỒ SƠ MCC chính sách.
 *
 * Chỉ gồm các chức năng: danh sách hồ sơ, tạo, xem chi tiết, chỉnh sửa.
 * Mỗi hồ sơ gắn một chính sách hoàn tiền; dữ liệu hồ sơ là nguồn cho "Tra cứu MCC".
 *
 * Phân quyền: đọc = `credit-cards.view`, ghi = `credit-cards.manage` (khớp các
 * trang quản trị thẻ tín dụng khác). Chỉ chính sách HỆ THỐNG mới thuộc phạm vi.
 *
 * KHÔNG đụng engine cashback, KHÔNG sửa `credit_card_policy_mcc_mappings`.
 */
class PolicyMccAdminController extends Controller
{
    public function __construct(private readonly PolicyMccProfileService $profiles) {}

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        $profiles = PolicyMccProfile::query()
            ->with([
                'template:id,name',
                'rows.codes',
                'documents',
            ])
            ->search($filters['name'])
            ->when($filters['template_id'] !== null, fn (Builder $query) => $query->forTemplate((int) $filters['template_id']))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.mcc-management.index', [
            'profiles' => $profiles->through(fn (PolicyMccProfile $profile) => $this->present($profile)),
            'templates' => $this->templateOptions(),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.mcc-management.create', [
            'templates' => $this->templateOptions(),
            'masterEndpoint' => route('admin.credit-card.mcc.master'),
            'initialName' => (string) old('name', ''),
            'initialTemplateId' => (int) old('policy_template_id', $request->integer('template_id') ?: 0) ?: null,
            'initialNote' => (string) old('note', ''),
            'initialAnnualFeePrimary' => (string) old('annual_fee_primary', ''),
            'initialAnnualFeeSupplementary' => (string) old('annual_fee_supplementary', ''),
            'initialAnnualSpendForWaiver' => (string) old('annual_spend_for_annual_fee_waiver', ''),
            'initialIncluded' => old('included', []),
            'initialExcluded' => old('excluded', []),
        ]);
    }

    public function store(StorePolicyMccProfileRequest $request): RedirectResponse
    {
        try {
            $profile = $this->profiles->create(
                $request->payload(),
                $request->documentFiles(),
                (int) $request->user()->id,
            );
        } catch (\InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['included' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.credit-card.mcc.show', $profile->id)
            ->with('status', 'Đã tạo hồ sơ MCC chính sách.');
    }

    public function show(PolicyMccProfile $profile): View
    {
        $profile->load([
            'template:id,name',
            'includedRows.codes.mccCode',
            'excludedRows.codes.mccCode',
            'documents',
        ]);

        return view('admin.mcc-management.show', [
            'profile' => $profile,
        ]);
    }

    public function edit(PolicyMccProfile $profile): View
    {
        $profile->load([
            'template:id,name',
            'includedRows.codes',
            'excludedRows.codes',
            'documents',
        ]);

        return view('admin.mcc-management.edit', [
            'profile' => $profile,
            'templates' => $this->templateOptions(),
            'masterEndpoint' => route('admin.credit-card.mcc.master'),
            'initialName' => (string) old('name', $profile->name),
            'initialTemplateId' => (int) $profile->policy_template_id,
            'initialNote' => (string) old('note', $profile->note ?? ''),
            'initialAnnualFeePrimary' => (string) old('annual_fee_primary', $profile->annual_fee_primary ?? ''),
            'initialAnnualFeeSupplementary' => (string) old('annual_fee_supplementary', $profile->annual_fee_supplementary ?? ''),
            'initialAnnualSpendForWaiver' => (string) old('annual_spend_for_annual_fee_waiver', $profile->annual_spend_for_annual_fee_waiver ?? ''),
            'initialIncluded' => old('included', $this->rowsToInitial($profile->includedRows)),
            'initialExcluded' => old('excluded', $this->rowsToInitial($profile->excludedRows)),
        ]);
    }

    public function update(UpdatePolicyMccProfileRequest $request, PolicyMccProfile $profile): RedirectResponse
    {
        try {
            $this->profiles->update(
                $profile,
                $request->payload(),
                $request->documentFiles(),
                (int) $request->user()->id,
            );
        } catch (\InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['included' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.credit-card.mcc.show', $profile->id)
            ->with('status', 'Đã cập nhật hồ sơ MCC chính sách.');
    }

    /**
     * Tải tài liệu ngân hàng đính kèm — KIỂM TRA QUYỀN trước khi trả file.
     * File nằm trên disk private, không public.
     */
    public function downloadDocument(PolicyMccProfile $profile, PolicyMccProfileDocument $document): StreamedResponse
    {
        abort_unless((int) $document->policy_mcc_profile_id === (int) $profile->id, 404);

        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);

        return Storage::disk($document->disk)->download($document->path, $document->original_name);
    }

    /**
     * Tìm MCC master cho ô chọn trong form (mã hoặc mô tả).
     */
    public function masterSearch(Request $request): JsonResponse
    {
        $term = (string) $request->query('q', '');

        $data = $this->profiles->masterSearch($term, 20)
            ->map(fn (MccCode $mcc): array => [
                'mcc' => $mcc->mcc,
                'description_vi' => $mcc->description_vi,
                'description_en' => $mcc->description_en,
                'verification_status' => $mcc->verification_status,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $data]);
    }

    /**
     * @return array{template_id:int|null, name:string}
     */
    private function filters(Request $request): array
    {
        return [
            'template_id' => $request->integer('template_id') ?: null,
            'name' => trim((string) $request->query('name', '')),
        ];
    }

    /**
     * @return array<int, array{id:int, name:string}>
     */
    private function templateOptions(): array
    {
        return PolicyTemplate::query()
            ->where('scope', PolicyTemplate::SCOPE_SYSTEM)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (PolicyTemplate $template): array => ['id' => (int) $template->id, 'name' => $template->name])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PolicyMccProfile $profile): array
    {
        $included = $profile->rows
            ->where('row_type', PolicyMccProfileRow::TYPE_INCLUDED)
            ->flatMap(fn (PolicyMccProfileRow $row) => $row->codes->pluck('mcc'))
            ->map(fn ($code): string => (string) $code)
            ->unique();

        $excluded = $profile->rows
            ->where('row_type', PolicyMccProfileRow::TYPE_EXCLUDED)
            ->flatMap(fn (PolicyMccProfileRow $row) => $row->codes->pluck('mcc'))
            ->map(fn ($code): string => (string) $code)
            ->unique();

        return [
            'id' => (int) $profile->id,
            'name' => $profile->name,
            'policy_name' => $profile->template?->name,
            'included_count' => $included->count(),
            'excluded_count' => $excluded->count(),
            'documents_count' => $profile->documents->count(),
            'updated_at' => $profile->updated_at?->format('d/m/Y H:i'),
        ];
    }

    /**
     * @param  Collection<int, PolicyMccProfileRow>  $rows
     * @return array<int, array{category_name:string|null, description:string|null, note:string|null, codes:array<int, string>}>
     */
    private function rowsToInitial($rows): array
    {
        return $rows
            ->map(fn (PolicyMccProfileRow $row): array => [
                'category_name' => $row->category_name,
                'description' => $row->description,
                'note' => $row->note,
                'codes' => $row->codes->pluck('mcc')->map(fn ($code): string => (string) $code)->values()->all(),
            ])
            ->values()
            ->all();
    }
}
