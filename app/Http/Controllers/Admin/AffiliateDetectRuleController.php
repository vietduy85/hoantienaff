<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AffiliateDetectRule;
use App\Services\Url\AffiliateDetectRuleRepository;
use App\Services\Url\CodeDetectRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * CRUD for admin-managed URL detection rules ("Chuỗi nhận diện URL").
 *
 * Hard-coded rules are read-only and ALWAYS evaluated first, so these rows are
 * an additive overlay: they can only affect URLs that are currently
 * unrecognised. Saving a rule never changes an existing platform's behaviour.
 */
class AffiliateDetectRuleController extends Controller
{
    public function __construct(
        private readonly AffiliateDetectRuleRepository $repository,
        private readonly CodeDetectRules $codeDetectRules,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePayload($request);

        $this->guardAgainstDuplicate(null, $validated['pattern'], $validated['match_type']);

        AffiliateDetectRule::create([
            ...$validated,
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
            'created_by' => $request->user()?->getAuthIdentifier(),
        ]);

        $this->repository->flush();

        return $this->backWithWarnings('Đã thêm chuỗi nhận diện.', $validated);
    }

    public function update(Request $request, AffiliateDetectRule $rule): RedirectResponse
    {
        if ($rule->source !== AffiliateDetectRule::SOURCE_DATABASE) {
            throw ValidationException::withMessages([
                'pattern' => 'Chỉ có thể sửa chuỗi do quản trị viên tạo.',
            ]);
        }

        $validated = $this->validatePayload($request);

        $this->guardAgainstDuplicate($rule->id, $validated['pattern'], $validated['match_type']);

        $rule->update($validated);

        $this->repository->flush();

        return $this->backWithWarnings('Đã cập nhật chuỗi nhận diện.', $validated);
    }

    public function destroy(AffiliateDetectRule $rule): RedirectResponse
    {
        if ($rule->source !== AffiliateDetectRule::SOURCE_DATABASE) {
            throw ValidationException::withMessages([
                'pattern' => 'Chỉ có thể xóa chuỗi do quản trị viên tạo.',
            ]);
        }

        $rule->delete();

        $this->repository->flush();

        return redirect()->route('admin.affiliate-config.index', ['tab' => 'partner'])
            ->with('success', 'Đã xóa chuỗi nhận diện.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'pattern' => ['required', 'string', 'max:255'],
            'match_type' => ['required', Rule::in([AffiliateDetectRule::MATCH_SUBSTRING, AffiliateDetectRule::MATCH_HOSTNAME])],
            'platform' => ['required', Rule::in(AffiliateDetectRule::PLATFORMS)],
            'strategy' => ['required', Rule::in(AffiliateDetectRule::STRATEGIES)],
            'campaign_id' => ['nullable', 'string', 'max:100'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'status' => ['required', Rule::in([AffiliateDetectRule::STATUS_ACTIVE, AffiliateDetectRule::STATUS_DISABLED])],
            'description' => ['nullable', 'string', 'max:500'],
        ], [
            'platform.in' => 'Nền tảng không hợp lệ. Chỉ được chọn trong danh sách có sẵn.',
            'strategy.in' => 'Phương thức không hợp lệ.',
            'match_type.in' => 'Kiểu khớp không hợp lệ.',
        ]);

        $validated['pattern'] = $this->normalizePattern($validated['pattern'], $validated['match_type']);
        $validated['priority'] = (int) ($validated['priority'] ?? 100);

        return $validated;
    }

    /**
     * Normalise a pattern the same way the resolver compares it: lower-case,
     * and for hostname rules strip scheme/path/port/`www.`.
     */
    private function normalizePattern(string $pattern, string $matchType): string
    {
        $pattern = strtolower(trim($pattern));

        if ($matchType !== AffiliateDetectRule::MATCH_HOSTNAME) {
            return $pattern;
        }

        if (str_contains($pattern, '://')) {
            $host = parse_url($pattern, PHP_URL_HOST);
            $pattern = is_string($host) && $host !== '' ? $host : $pattern;
        }

        $pattern = preg_replace('~[/?#].*$~', '', $pattern) ?? $pattern;
        $pattern = preg_replace('/:\d+$/', '', $pattern) ?? $pattern;
        $pattern = preg_replace('/^www\./', '', $pattern) ?? $pattern;

        return trim($pattern, '.');
    }

    /**
     * Block an exact duplicate (same pattern + match type) among admin rules.
     */
    private function guardAgainstDuplicate(?int $ignoreId, string $pattern, string $matchType): void
    {
        $exists = AffiliateDetectRule::query()
            ->where('pattern', $pattern)
            ->where('match_type', $matchType)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'pattern' => 'Đã tồn tại chuỗi nhận diện trùng (cùng chuỗi và kiểu khớp).',
            ]);
        }
    }

    /**
     * Informational conflicts. These do NOT block saving: because code rules
     * run first, a rule that overlaps a code rule simply never applies. We warn
     * so the admin understands why.
     *
     * @param  array<string, mixed>  $validated
     */
    private function backWithWarnings(string $successMessage, array $validated): RedirectResponse
    {
        $warnings = $this->warningsFor($validated['pattern'], $validated['match_type']);

        $redirect = redirect()->route('admin.affiliate-config.index', ['tab' => 'partner'])
            ->with('success', $successMessage);

        if ($warnings !== []) {
            $redirect->with('rule_warnings', $warnings);
        }

        return $redirect;
    }

    /**
     * @return array<int, string>
     */
    private function warningsFor(string $pattern, string $matchType): array
    {
        $warnings = [];

        foreach ($this->codeDetectRules->flat() as $codeRule) {
            if (strtolower($codeRule['pattern']) !== $pattern) {
                continue;
            }

            $warnings[] = $codeRule['match'] === $matchType
                ? "Chuỗi \"{$pattern}\" đã tồn tại trong code (kiểu {$matchType}). Vì code chạy trước, rule này sẽ không bao giờ được áp dụng cho URL đã nhận diện."
                : "Chuỗi \"{$pattern}\" đã tồn tại trong code với kiểu khớp \"{$codeRule['match']}\" khác với rule mới (\"{$matchType}\"). Rule chỉ áp dụng cho URL mà code chưa nhận diện.";
        }

        return $warnings;
    }
}
