<?php

namespace Tests\Fixture;

use App\Services\Lazada\DTOs\LazadaConversionRecord;

/**
 * Builds a realistic Lazada conversion-report record for tests.
 *
 * Mirrors the documented field names of GET /marketing/conversion/report.
 */
class LazadaConversionFixture
{
    public static function record(array $overrides = []): LazadaConversionRecord
    {
        return LazadaConversionRecord::fromArray(array_merge(self::base(), $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    public static function base(): array
    {
        return [
            'conversionTime' => '2026-08-01 10:00:00',
            'offerName'      => 'Offer A',
            'offerId'        => '12345',
            'offerType'      => 'cps',
            'status'         => 'fulfilled',
            'platform'       => 'Lazada',
            'country'        => 'VN',
            'orderId'        => '839912345678901',
            'subOrderId'     => '839912345678902',
            'sku'            => '6021831634002',
            'categoryL1'     => 'Beauty',
            'commissionRate'        => '6.00',
            'baseCommissionRate'    => '4.00',
            'bonusCommissionRate'   => '2.00',
            'newUser'        => '0',
            'estPayout'      => '12000.00',
            'currency'       => 'VND',
            'affiliateSubId' => 'hoantienaff',
            'subId1'         => '5',
            'subId2'         => 'alice123',
            'subId3'         => '',
            'subId4'         => '',
            'subId5'         => '',
            'subId6'         => '',
            'orderAmt'       => '200000.00',
            'fulfilledTime'  => '2026-08-02 09:00:00',
            'deliveredTime'  => '2026-08-04 09:00:00',
            'returnedTime'   => null,
            'validity'       => '1',
            'bonusPayout'    => '4000.00',
            'basePayout'     => '8000.00',
            'brandId'        => '111',
            'brandName'      => 'Brand X',
            'sellerId'       => '679188',
            'sellerName'     => 'Seller X',
            'isMmCommission' => '0',
            'pdpUrl'         => 'https://www.lazada.vn/products/brand-x-i12345.html',
            'memberId'       => '0',
            'skuName'        => 'Son Kem Brand X 01',
            'commissionType' => 'cps',
        ];
    }
}