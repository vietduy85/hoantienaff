# Cashback

## Các khái niệm

| Thuật ngữ | Ý nghĩa | Nguồn |
|-----------|---------|-------|
| `commission` | Hoa hồng gốc từ Shopee (đơn vị: VND) | AddLiveTag API → `productInfo.commission` |
| `product_price` | Giá sản phẩm (đơn vị: VND) | AddLiveTag API → `productInfo.price` |
| `estimated_cashback` | = `commission` (giá trị tương tự) | Lưu trong link_requests.estimated_cashback |
| `user_estimated_cashback` | Cashback user thực nhận (sau thuế) | Tính từ CashbackCalculator |
| `cashback_rate` | Tỷ lệ cashback (50%, 60%, 70%) | Tính từ commission_rate |
| `seller_commission` | Hoa hồng người bán trả | AddLiveTag API → `productInfo.sellerComFinal` |
| `shopee_commission` | Hoa hồng Shopee giữ lại | AddLiveTag API → `productInfo.shopeeComFinal` |

## Công thức tính

### 1. Commission Rate

```
commission_rate = commission / product_price
```

### 2. Cashback Rate

```
Nếu commission_rate >= 0.52 (52%) → cashback_rate = 0.70 (70%)
Nếu commission_rate >= 0.12 (12%) → cashback_rate = 0.60 (60%)
Nếu commission_rate <  0.12 (12%) → cashback_rate = 0.50 (50%)
```

### 3. User Cashback (sau thuế)

```
net_cashback = floor(commission × 0.90)          // Trừ 10% thuế
user_cashback = floor(net_cashback × cashback_rate)
```

## Ví dụ tính toán

### Ví dụ 1: Sản phẩm giá 200,000đ, commission 30,000đ

```
commission_rate = 30,000 / 200,000 = 0.15 (15%)
→ cashback_rate = 0.60 (60%) — vì 15% >= 12%

net_cashback = floor(30,000 × 0.90) = 27,000
user_cashback = floor(27,000 × 0.60) = 16,200

Kết quả:
- cashback_rate: 0.60
- user_estimated_cashback: 16,200đ
```

### Ví dụ 2: Sản phẩm giá 500,000đ, commission 300,000đ

```
commission_rate = 300,000 / 500,000 = 0.60 (60%)
→ cashback_rate = 0.70 (70%) — vì 60% >= 52%

net_cashback = floor(300,000 × 0.90) = 270,000
user_cashback = floor(270,000 × 0.70) = 189,000

Kết quả:
- cashback_rate: 0.70
- user_estimated_cashback: 189,000đ
```

### Ví dụ 3: Sản phẩm giá 100,000đ, commission 5,000đ

```
commission_rate = 5,000 / 100,000 = 0.05 (5%)
→ cashback_rate = 0.50 (50%) — vì 5% < 12%

net_cashback = floor(5,000 × 0.90) = 4,500
user_cashback = floor(4,500 × 0.50) = 2,250

Kết quả:
- cashback_rate: 0.50
- user_estimated_cashback: 2,250đ
```

### Ví dụ 4: Giá hoặc commission = 0

```
product_price = 0 hoặc commission = 0
→ cashback_rate = 0.50 (mặc định)
→ user_estimated_cashback = 0

Kết quả: {cashback_rate: 0.50, user_estimated_cashback: 0}
```

## Source code

```php
// app/Services/CashbackCalculator.php
class CashbackCalculator
{
    private const RATE_50 = 0.50;
    private const RATE_60 = 0.60;
    private const RATE_70 = 0.70;

    private const THRESHOLD_60 = 0.12;  // 12%
    private const THRESHOLD_70 = 0.52;  // 52%

    public function calculate(float $estimatedCashback, float $productPrice): array
    {
        if ($productPrice <= 0 || $estimatedCashback <= 0) {
            return [
                'cashback_rate' => self::RATE_50,
                'user_estimated_cashback' => 0,
            ];
        }

        $commissionRate = $estimatedCashback / $productPrice;

        $rate = match (true) {
            $commissionRate >= self::THRESHOLD_70 => self::RATE_70,
            $commissionRate >= self::THRESHOLD_60 => self::RATE_60,
            default => self::RATE_50,
        };

        $netCashback = (int) floor($estimatedCashback * 0.90);
        $userCashback = (int) floor($netCashback * $rate);

        return [
            'cashback_rate' => $rate,
            'user_estimated_cashback' => $userCashback,
        ];
    }
}
```

## Sơ đồ

```mermaid
flowchart TD
    A[commission, price] --> B{price > 0 AND commission > 0?}
    B -->|No| C[rate=50%, user_cashback=0]
    B -->|Yes| D[commission_rate = commission / price]
    D --> E{commission_rate >= 52%?}
    E -->|Yes| F[rate = 70%]
    E -->|No| G{commission_rate >= 12%?}
    G -->|Yes| H[rate = 60%]
    G -->|No| I[rate = 50%]
    F --> J[net = floor(commission × 0.90)]
    H --> J
    I --> J
    J --> K[user = floor(net × rate)]
    C --> L[Kết quả]
    K --> L
```

## Lưu ý

1. **Thuế 10%**: Luôn trừ 10% commission trước khi tính cashback. Đây là thuế TNCN hoặc phí nền tảng.
2. **floor()**: Dùng `floor` để làm tròn xuống (đảm bảo không trả nhiều hơn thực tế).
3. **cashback_rate lưu trong DB**: 0.50, 0.60, 0.70 (dạng decimal, không phải phần trăm).
4. **Edge case**: Khi `commission` > `product_price` (commission_rate > 100%), vẫn tính bình thường — rate 70%.
5. Các field `seller_commission`, `shopee_commission` từ API AddLiveTag chỉ để tham khảo, không dùng trong tính toán cashback.
