<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\PolicyMccProfile;
use App\Models\CreditCard\PolicyMccProfileDocument;
use App\Models\CreditCard\PolicyMccProfileRow;
use App\Models\CreditCard\PolicyMccRowBenefit;
use App\Models\CreditCard\PolicyTemplate;
use App\Services\CreditCard\Import\MccRowBenefitImporter;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Trình nhập "dữ liệu hoàn tiền theo dòng MCC" — idempotent + định vị theo cấu
 * trúc (slug template → hồ sơ đầu tiên → dòng included theo category_name).
 */
class MccRowBenefitImportTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    /**
     * Ghi một file dữ liệu tạm (cùng định dạng `database/data/...`) và trả path.
     *
     * @param  array<string, mixed>  $data
     */
    private function writeDataFile(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mcc_benefits_');
        file_put_contents($path, '<?php return '.var_export($data, true).';');

        $this->tempFiles[] = $path;

        return $path;
    }

    private function makeTemplate(string $slug): PolicyTemplate
    {
        return $this->makeSystemTemplate(['slug' => $slug, 'name' => 'Template '.$slug]);
    }

    private function makeProfileWithRow(PolicyTemplate $template, string $categoryName = 'Bảo hiểm'): PolicyMccProfileRow
    {
        $profile = PolicyMccProfile::create([
            'policy_template_id' => $template->id,
            'name' => 'Hồ sơ '.$template->name,
        ]);

        return PolicyMccProfileRow::create([
            'policy_mcc_profile_id' => $profile->id,
            'row_type' => PolicyMccProfileRow::TYPE_INCLUDED,
            'category_name' => $categoryName,
        ]);
    }

    private function makeDocument(PolicyMccProfile $profile, string $name): PolicyMccProfileDocument
    {
        return PolicyMccProfileDocument::create([
            'policy_mcc_profile_id' => $profile->id,
            'disk' => 'local',
            'path' => 'credit-card-mcc/test/'.$name,
            'original_name' => $name,
            'size' => 1,
        ]);
    }

    #[Test]
    public function import_creates_tiers_and_resolves_source_document_and_page(): void
    {
        $template = $this->makeTemplate('the-test-bao-hiem');
        $row = $this->makeProfileWithRow($template);
        $document = $this->makeDocument($row->profile, 'LPBank JCB Ultimate - Điều khoản.pdf');

        $path = $this->writeDataFile([
            'the-test-bao-hiem' => [
                'source_doc' => 'JCB Ultimate',
                'source_page' => 'trang 3',
                'rows' => [
                    'Bảo hiểm' => [
                        ['pct' => 7.5, 'cap' => 150000, 'cap_period' => 'kỳ sao kê', 'cap_unit' => 'VND', 'cond' => 'Dưới 15tr'],
                        ['pct' => 15, 'min' => 15000000, 'cap' => 400000, 'cap_period' => 'kỳ sao kê', 'cap_unit' => 'VND', 'exc' => 'Không áp dụng thẻ phụ', 'page' => 'trang 5'],
                    ],
                ],
            ],
        ]);

        $stats = app(MccRowBenefitImporter::class)->import($path);

        $this->assertSame(1, $stats['profiles_imported']);
        $this->assertSame(1, $stats['rows_imported']);
        $this->assertSame(2, $stats['tiers_created']);
        $this->assertSame(0, $stats['tiers_updated']);
        $this->assertSame(0, $stats['tiers_removed']);
        $this->assertSame([], $stats['skipped_profiles']);
        $this->assertSame([], $stats['skipped_rows']);

        $tiers = PolicyMccRowBenefit::query()->where('policy_mcc_row_id', $row->id)->orderBy('tier_order')->get();

        $this->assertCount(2, $tiers);
        $this->assertSame('7.500', $tiers[0]->cashback_percent);
        $this->assertSame('150000.00', $tiers[0]->cap_amount);
        $this->assertSame('Dưới 15tr', $tiers[0]->condition_note);
        $this->assertSame($document->id, $tiers[0]->source_document_id);
        // Không có page riêng ⇒ dùng source_page mặc định của profile.
        $this->assertSame('trang 3', $tiers[0]->source_page);

        $this->assertSame('15.000', $tiers[1]->cashback_percent);
        $this->assertSame('15000000.00', $tiers[1]->min_spend);
        $this->assertSame('trang 5', $tiers[1]->source_page);
        $this->assertSame('Không áp dụng thẻ phụ', $tiers[1]->exception_note);
    }

    #[Test]
    public function import_is_idempotent_on_second_run(): void
    {
        $template = $this->makeTemplate('the-test-idempotent');
        $this->makeProfileWithRow($template);

        $path = $this->writeDataFile([
            'the-test-idempotent' => [
                'rows' => [
                    'Bảo hiểm' => [
                        ['pct' => 15, 'cap' => 400000],
                    ],
                ],
            ],
        ]);

        $importer = app(MccRowBenefitImporter::class);
        $importer->import($path);
        $second = $importer->import($path);

        $this->assertSame(0, $second['tiers_created']);
        $this->assertSame(0, $second['tiers_updated']);
        $this->assertSame(0, $second['tiers_removed']);
        $this->assertSame(1, PolicyMccRowBenefit::query()->count());
    }

    #[Test]
    public function import_removes_tiers_when_data_is_shortened(): void
    {
        $template = $this->makeTemplate('the-test-shorten');
        $row = $this->makeProfileWithRow($template);

        $importer = app(MccRowBenefitImporter::class);

        $importer->import($this->writeDataFile([
            'the-test-shorten' => [
                'rows' => [
                    'Bảo hiểm' => [
                        ['pct' => 7.5],
                        ['pct' => 10],
                        ['pct' => 15],
                    ],
                ],
            ],
        ]));

        $this->assertSame(3, PolicyMccRowBenefit::query()->where('policy_mcc_row_id', $row->id)->count());

        $stats = $importer->import($this->writeDataFile([
            'the-test-shorten' => [
                'rows' => [
                    'Bảo hiểm' => [
                        ['pct' => 15],
                    ],
                ],
            ],
        ]));

        $this->assertSame(2, $stats['tiers_removed']);
        $this->assertSame(1, PolicyMccRowBenefit::query()->where('policy_mcc_row_id', $row->id)->count());
    }

    #[Test]
    public function import_skips_unknown_slug_and_unmatched_category(): void
    {
        $template = $this->makeTemplate('the-test-known');
        $this->makeProfileWithRow($template, 'Bảo hiểm');

        $stats = app(MccRowBenefitImporter::class)->import($this->writeDataFile([
            'khong-ton-tai' => [
                'rows' => ['Bảo hiểm' => [['pct' => 10]]],
            ],
            'the-test-known' => [
                'rows' => ['Danh mục lạ' => [['pct' => 10]]],
            ],
        ]));

        // Template "known" vẫn được tính là đã xử lý (tìm thấy hồ sơ) dù không
        // có dòng nào khớp; slug lạ vào skipped_profiles, dòng lạ vào skipped_rows.
        $this->assertSame(1, $stats['profiles_imported']);
        $this->assertSame(0, $stats['rows_imported']);
        $this->assertCount(1, $stats['skipped_profiles']);
        $this->assertCount(1, $stats['skipped_rows']);
        $this->assertSame(0, PolicyMccRowBenefit::query()->count());
    }

    #[Test]
    public function import_marks_bonus_tier_via_source_note(): void
    {
        $template = $this->makeTemplate('the-test-bonus');
        $row = $this->makeProfileWithRow($template, 'Siêu thị, cửa hàng tiện lợi');

        $path = $this->writeDataFile([
            'the-test-bonus' => [
                'rows' => [
                    'Siêu thị, cửa hàng tiện lợi' => [
                        ['pct' => 50, 'type' => 'bonus', 'cap' => 300000, 'cond' => 'Giao dịch đầu tiên trong 30 ngày kích hoạt'],
                        ['pct' => 20, 'cap' => 300000, 'cond' => 'Các giao dịch còn lại'],
                    ],
                ],
            ],
        ]);

        $importer = app(MccRowBenefitImporter::class);
        $importer->import($path);

        $tiers = PolicyMccRowBenefit::query()->where('policy_mcc_row_id', $row->id)->orderBy('tier_order')->get();

        $this->assertCount(2, $tiers);
        // Bậc 50% là ưu đãi bổ sung ⇒ đánh dấu `bonus` ở cột source_note.
        $this->assertSame('bonus', $tiers[0]->source_note);
        $this->assertTrue($tiers[0]->isBonus());
        $this->assertSame(PolicyMccRowBenefit::KIND_BONUS, $tiers[0]->benefitKind());
        // Bậc 20% không có dấu ⇒ cashback chính thức (mặc định).
        $this->assertNull($tiers[1]->source_note);
        $this->assertFalse($tiers[1]->isBonus());
        $this->assertSame(PolicyMccRowBenefit::KIND_OFFICIAL, $tiers[1]->benefitKind());

        // Chạy lại không ghi đè/thêm: idempotent.
        $second = $importer->import($path);
        $this->assertSame(0, $second['tiers_created']);
        $this->assertSame(0, $second['tiers_updated']);
        $this->assertSame('bonus', PolicyMccRowBenefit::query()->where('policy_mcc_row_id', $row->id)->orderBy('tier_order')->first()->source_note);
    }

    #[Test]
    public function import_leaves_official_tier_unmarked(): void
    {
        $template = $this->makeTemplate('the-test-official');
        $row = $this->makeProfileWithRow($template, 'Y tế');

        $this->writeAndImport($template, [
            ['pct' => 15, 'cap' => 400000],
        ]);

        $tier = PolicyMccRowBenefit::query()->where('policy_mcc_row_id', $row->id)->firstOrFail();

        $this->assertNull($tier->source_note);
        $this->assertFalse($tier->isBonus());
        $this->assertSame(PolicyMccRowBenefit::KIND_OFFICIAL, $tier->benefitKind());
    }

    /**
     * Ghi file dữ liệu tạm cho MỘT template/dòng `Bảo hiểm`… và chạy trình nhập.
     *
     * @param  array<int, array<string, mixed>>  $tiers
     */
    private function writeAndImport(PolicyTemplate $template, array $tiers): void
    {
        app(MccRowBenefitImporter::class)->import($this->writeDataFile([
            $template->slug => [
                'rows' => [
                    'Y tế' => $tiers,
                ],
            ],
        ]));
    }
}
