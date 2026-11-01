<?php

namespace App\Services\Payout;

use App\Models\WithdrawRequest;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Str;

/**
 * Tạo chuỗi VietQR (chuẩn EMVCo/NAPAS) để admin quét bằng app ngân hàng chuyển tiền rút.
 * Sinh hoàn toàn tại máy chủ — không gửi STK của user ra dịch vụ ngoài.
 */
class VietQrPayload
{
    public static function binFor(?string $bankName): ?string
    {
        return $bankName ? (config('banks.bins')[$bankName] ?? null) : null;
    }

    /** Nội dung chuyển khoản: không dấu, ngắn để mọi ngân hàng chấp nhận. */
    public static function transferNote(WithdrawRequest $request): string
    {
        return 'HOANTIEN '.$request->running_no;
    }

    public static function forWithdraw(WithdrawRequest $request): ?string
    {
        $bin = self::binFor($request->bank_name);

        if ($bin === null || ! $request->bank_account) {
            return null;
        }

        return self::build($bin, (string) $request->bank_account, (int) round((float) $request->amount), self::transferNote($request));
    }

    public const APP_PLACEHOLDER = '__APP__';

    /**
     * Link dl.vietqr.io mở app ngân hàng của admin với lệnh chuyển điền sẵn (app có autofill).
     * Tham số app để placeholder, trang duyệt thay bằng app admin đã chọn.
     * Lưu ý: STK/số tiền đi qua máy chủ VietQR (Casso) để chuyển cho app ngân hàng.
     */
    public static function deeplink(WithdrawRequest $request, string $returnUrl): ?string
    {
        $bin = self::binFor($request->bank_name);
        $code = $bin ? (config('banks.vietqr_codes')[$bin] ?? null) : null;

        if ($code === null || ! $request->bank_account) {
            return null;
        }

        return 'https://dl.vietqr.io/pay?'.http_build_query([
            'app' => self::APP_PLACEHOLDER,
            'ba' => preg_replace('/\s+/', '', (string) $request->bank_account).'@'.strtolower($code),
            'am' => (int) round((float) $request->amount),
            'tn' => self::transferNote($request),
            'bn' => Str::ascii((string) $request->account_name),
            'url' => $returnUrl,
        ]);
    }

    public static function build(string $bin, string $account, int $amount, string $note): string
    {
        $account = preg_replace('/\s+/', '', $account);
        $note = Str::limit(preg_replace('/[^A-Za-z0-9 ]/', '', Str::ascii($note)), 50, '');

        $beneficiary = self::tlv('00', $bin).self::tlv('01', $account);
        $merchant = self::tlv('00', 'A000000727').self::tlv('01', $beneficiary).self::tlv('02', 'QRIBFTTA');

        $payload = self::tlv('00', '01')
            .self::tlv('01', '12')
            .self::tlv('38', $merchant)
            .self::tlv('53', '704')
            .($amount > 0 ? self::tlv('54', (string) $amount) : '')
            .self::tlv('58', 'VN')
            .($note !== '' ? self::tlv('62', self::tlv('08', $note)) : '')
            .'6304';

        return $payload.self::crc16($payload);
    }

    public static function svg(string $payload): string
    {
        $options = new QROptions([
            'outputBase64' => false,
            'outputType' => 'svg',
            'svgAddXmlHeader' => false,
            'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M,
        ]);

        return (new QRCode($options))->render($payload);
    }

    private static function tlv(string $id, string $value): string
    {
        return $id.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
    }

    /** CRC16-CCITT (poly 0x1021, init 0xFFFF) theo chuẩn EMVCo. */
    private static function crc16(string $data): string
    {
        $crc = 0xFFFF;
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($b = 0; $b < 8; $b++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
