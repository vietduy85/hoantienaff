<?php

namespace Tests\Unit\Services\TikTok;

use App\Services\TikTok\DTOs\TikTokOrder;
use Tests\TestCase;

class TikTokOrderTest extends TestCase
{
    public function test_to_database_array_maps_riohub_fields_correctly(): void
    {
        $order = TikTokOrder::fromArray([
            'order_id' => '579012345678901234',
            'sku_id' => '173098765432109876',
            'product_id' => '172900112233445566',
            'product_name' => 'Áo thun cotton unisex',
            'price' => '199000.00',
            'quantity' => 1,
            'refunded_quantity' => 0,
            'shop_name' => 'Rio Official Store',
            'settlement_status' => 'AWAITING PAYMENT',
            'status' => 1,
            'content_type' => 'LINKSHARE',
            'sub_id' => 'creator_main',
            'sub1' => 'user123',
            'sub2' => 'm1780903356',
            'sub3' => '',
            'sub4' => '',
            'commission_model' => 'Fixed commission',
            'standard_commission_rate' => '800',
            'commission_rate' => 2800,
            'commission_bonus_rate' => 2000,
            'commission_gmv' => '199000.00',
            'est_standard_commission' => '15920.00',
            'est_bonus_commission' => '39800.00',
            'est_commission' => '55720.00',
            'actual_commission' => null,
            'time_created' => '2026-06-10 08:00:00',
            'time_delivered' => null,
            'payment_status' => 'AWAITING PAYMENT',
        ]);

        $dbArray = $order->toDatabaseArray('user123', 42, '20260721_100000');

        $this->assertEquals('579012345678901234', $dbArray['order_id']);
        $this->assertEquals('AWAITING PAYMENT', $dbArray['order_status']);
        $this->assertEquals('2026-06-10 08:00:00', $dbArray['ordered_at']);
        $this->assertNull($dbArray['completed_at']);
        $this->assertEquals('Rio Official Store', $dbArray['shop_name']);
        $this->assertEquals(172900112233445566, $dbArray['item_id']);
        $this->assertEquals('Áo thun cotton unisex', $dbArray['item_name']);
        $this->assertEquals(173098765432109876, $dbArray['model_id']);
        $this->assertEquals(199000.0, $dbArray['item_price']);
        $this->assertEquals(1, $dbArray['quantity']);
        $this->assertEquals(199000.0, $dbArray['order_amount']);
        $this->assertEquals('Fixed commission', $dbArray['commission_type']);
        $this->assertEquals(8.0, $dbArray['shopee_commission_rate']);
        $this->assertEquals(15920.0, $dbArray['shopee_commission']);
        $this->assertEquals(39800.0, $dbArray['xtra_commission']);
        $this->assertEquals(55720.0, $dbArray['total_product_commission']);
        $this->assertEquals(55720.0, $dbArray['total_order_commission']);
        $this->assertEquals(28.0, $dbArray['agreed_commission_rate']);
        $this->assertEquals(55720.0, $dbArray['net_commission']);
        $this->assertEquals('Đang xử lý', $dbArray['affiliate_status']);
        $this->assertEquals('AWAITING PAYMENT', $dbArray['buyer_status']);
        $this->assertEquals('creator_main', $dbArray['sub_id1']);
        $this->assertEquals('user123', $dbArray['sub_id2']);
        $this->assertEquals('m1780903356', $dbArray['sub_id3']);
        $this->assertEquals('', $dbArray['sub_id4']);
        $this->assertEquals('', $dbArray['sub_id5']);
        $this->assertEquals('LINKSHARE', $dbArray['channel']);
        $this->assertEquals('TikTok', $dbArray['platform']);
        $this->assertEquals(42, $dbArray['user_id']);
        $this->assertEquals('user123', $dbArray['username']);
        $this->assertEquals('rioHub-api', $dbArray['source_file']);
        $this->assertNull($dbArray['locked_at']);
    }

    public function test_to_database_array_settled_order_locks(): void
    {
        $order = TikTokOrder::fromArray([
            'order_id' => 'SETTLED-001',
            'status' => 2,
            'settlement_status' => 'SETTLED',
            'est_commission' => 10000,
            'actual_commission' => 9500,
        ]);

        $dbArray = $order->toDatabaseArray('user1', 1, '20260721');

        $this->assertEquals('Hoàn thành', $dbArray['affiliate_status']);
        $this->assertEquals('SETTLED', $dbArray['order_status']);
        $this->assertEquals(9500.0, $dbArray['net_commission']);
        $this->assertNotNull($dbArray['locked_at']);
    }

    public function test_to_database_array_cancelled_order_locks(): void
    {
        $order = TikTokOrder::fromArray([
            'order_id' => 'CANCEL-001',
            'status' => 3,
            'settlement_status' => 'REFUNDED',
        ]);

        $dbArray = $order->toDatabaseArray('user1', 1, '20260721');

        $this->assertEquals('Đã hủy', $dbArray['affiliate_status']);
        $this->assertNotNull($dbArray['locked_at']);
    }

    public function test_to_database_array_refund_amount_calculation(): void
    {
        $order = TikTokOrder::fromArray([
            'order_id' => 'REFUND-001',
            'price' => 100000.0,
            'refunded_quantity' => 2,
            'quantity' => 5,
        ]);

        $dbArray = $order->toDatabaseArray('user1', 1, '20260721');

        $this->assertEquals(200000.0, $dbArray['refund_amount']);
    }

    public function test_to_database_array_null_user_when_not_found(): void
    {
        $order = TikTokOrder::fromArray([
            'order_id' => 'NOUSER-001',
            'sub1' => 'unknown_user',
        ]);

        $dbArray = $order->toDatabaseArray('unknown_user', null, '20260721');

        $this->assertNull($dbArray['user_id']);
        $this->assertEquals('unknown_user', $dbArray['username']);
        $this->assertNull($dbArray['sub_id1']);
        $this->assertEquals('unknown_user', $dbArray['sub_id2']);
    }

    public function test_map_status_returns_correct_values(): void
    {
        $status1 = TikTokOrder::fromArray(['order_id' => 'A', 'status' => 1]);
        $status2 = TikTokOrder::fromArray(['order_id' => 'B', 'status' => 2]);
        $status3 = TikTokOrder::fromArray(['order_id' => 'C', 'status' => 3]);
        $statusNull = TikTokOrder::fromArray(['order_id' => 'D']);

        $this->assertEquals('Đang xử lý', $status1->toDatabaseArray('u', null, 'b')['affiliate_status']);
        $this->assertEquals('Hoàn thành', $status2->toDatabaseArray('u', null, 'b')['affiliate_status']);
        $this->assertEquals('Đã hủy', $status3->toDatabaseArray('u', null, 'b')['affiliate_status']);
        $this->assertEquals('Đang xử lý', $statusNull->toDatabaseArray('u', null, 'b')['affiliate_status']);
    }

    public function test_from_array_ignores_unknown_keys(): void
    {
        $order = TikTokOrder::fromArray([
            'order_id' => 'X',
            'returned_quantity' => 5,
            'fully_refunded' => 1,
            'currency' => 'VND',
            'sub_id' => 'abc-def',
            'content_id' => '123',
            'tt_order_status' => 103,
        ]);

        $this->assertEquals('X', $order->getOrderId());
        $this->assertNull($order->getRefundedQuantity());
    }

    public function test_maps_new_riohub_fields(): void
    {
        $order = TikTokOrder::fromArray([
            'order_id'                      => 'ORD-NEW',
            'content_id'                    => '7495366414587628324',
            'returned_quantity'             => 2,
            'fully_refunded'                => 1,
            'currency'                      => 'VND',
            'actual_standard_commission'    => '8848.00',
            'create_time'                   => 1785195821,
            'update_time'                   => 1785420652,
            'pit'                           => '0.00',
            'time_created'                  => null,
            'status'                        => 2,
            'tt_order_status'               => 103,
            'shop_ads_commission_rate'      => 0,
            'est_shop_ads_commission'       => null,
            'actual_shop_ads_commission'    => null,
            'actual_bonus_commission'       => '500.00',
            'actual_creator_commission_reward_fee' => 120.5,
        ]);

        $this->assertEquals('7495366414587628324', $order->getContentId());
        $this->assertEquals(2, $order->getReturnedQuantity());
        $this->assertTrue($order->isFullyRefunded());
        $this->assertEquals('VND', $order->getCurrency());
        $this->assertEquals(8848.0, $order->getActualStandardCommission());
        $this->assertEquals(1785195821, $order->getCreateTime());
        $this->assertEquals(1785420652, $order->getUpdateTime());
        $this->assertEquals(103, $order->getTtOrderStatus());
        $this->assertEquals(0.0, $order->getShopAdsCommissionRate());
        $this->assertNull($order->getEstShopAdsCommission());
        $this->assertNull($order->getActualShopAdsCommission());
        $this->assertEquals(500.0, $order->getActualBonusCommission());
        $this->assertEquals(120.5, $order->getActualCreatorCommissionRewardFee());
        $this->assertEquals('0.00', $order->getPit());
        $this->assertTrue($order->isSettled());

        $dbArray = $order->toDatabaseArray('user1', 42, '20260721');

        $this->assertSame('', $dbArray['checkout_id']);
        $this->assertEquals('7495366414587628324', $dbArray['content_id']);
        $this->assertEquals(date('Y-m-d H:i:s', 1785195821), $dbArray['ordered_at']);
        $this->assertStringStartsWith(date('Y-m-d'), $dbArray['last_tiktok_sync_at']);
        $this->assertArrayNotHasKey('last_shopee_sync_at', $dbArray);
        $this->assertSame('Hoàn thành', $dbArray['affiliate_status']);
    }
}
