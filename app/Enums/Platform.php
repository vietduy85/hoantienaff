<?php

namespace App\Enums;

enum Platform: string
{
    case SHOPEE = 'shopee';
    case LAZADA = 'lazada';
    case TIKTOK = 'tiktok';
    case LONG_CHAU = 'longchau';
    case PHARMACITY = 'pharmacity';
    case TRAVELOKA = 'traveloka';
    case AGODA = 'agoda';
    case BOOKING = 'booking';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::SHOPEE => 'Shopee',
            self::LAZADA => 'Lazada',
            self::TIKTOK => 'TikTok Shop',
            self::LONG_CHAU => 'Nhà Thuốc Long Châu',
            self::PHARMACITY => 'Pharmacity',
            self::TRAVELOKA => 'Traveloka',
            self::AGODA => 'Agoda',
            self::BOOKING => 'Booking.com',
            self::OTHER => 'Khác',
        };
    }
}
