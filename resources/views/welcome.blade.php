<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Hoàn Tiền') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #F8F8F8;
            color: #1a1a1a;
            min-height: 100vh;
        }
        .container {
            width: 100%;
            max-width: 480px;
            margin: 0 auto;
            padding: 0 20px 24px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        /* ───── Hero ───── */
        .hero {
            width: 100%;
            height: 330px;
            background: linear-gradient(160deg, #FF6B35 0%, #FF8F5E 40%, #fff0e8 100%);
            border-radius: 0 0 32px 32px;
            position: relative;
            overflow: hidden;
            margin: 0 -20px;
            padding: 0 20px;
            box-shadow: 0 8px 32px rgba(255, 107, 53, 0.2);
            flex-shrink: 0;
        }
        .hero-content {
            position: relative;
            z-index: 2;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding-top: 12px;
        }
        .hero-title {
            font-size: 42px;
            font-weight: 900;
            color: #fff;
            letter-spacing: -1px;
            line-height: 1.1;
            text-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }
        .hero-sub {
            font-size: 15px;
            color: rgba(255,255,255,0.85);
            margin-top: 4px;
            font-weight: 500;
        }
        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(8px);
            padding: 8px 16px;
            border-radius: 100px;
            font-size: 12px;
            font-weight: 600;
            color: #fff;
            margin-top: 16px;
            width: fit-content;
            border: 1px solid rgba(255,255,255,0.25);
        }
        .hero-badge-fire {
            font-size: 15px;
        }

        /* Illustrations */
        .hero-phone {
            position: absolute;
            width: 72px;
            height: 126px;
            background: #fff;
            border-radius: 14px;
            bottom: 20px;
            left: 16px;
            z-index: 1;
            box-shadow: 0 6px 24px rgba(0,0,0,0.12);
        }
        .hero-phone::before {
            content: '';
            position: absolute;
            top: 7px;
            left: 50%;
            transform: translateX(-50%);
            width: 28px;
            height: 3px;
            background: #ddd;
            border-radius: 4px;
        }
        .hero-phone-screen {
            position: absolute;
            top: 16px;
            left: 7px;
            right: 7px;
            bottom: 10px;
            background: linear-gradient(180deg, #FF6B35 0%, #FF8F5E 100%);
            border-radius: 7px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .hero-phone-screen::after {
            content: '$';
            font-size: 22px;
            font-weight: 900;
            color: #fff;
        }

        .hero-girl {
            position: absolute;
            width: 90px;
            height: 135px;
            bottom: 16px;
            right: 24px;
            z-index: 1;
        }
        .hero-girl-head {
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 36px;
            height: 36px;
            background: #FFD0B5;
            border-radius: 50%;
        }
        .hero-girl-head::after {
            content: '';
            position: absolute;
            top: -7px;
            left: -4px;
            width: 44px;
            height: 18px;
            background: #1a1a1a;
            border-radius: 22px 22px 0 0;
        }
        .hero-girl-head::before {
            content: '';
            position: absolute;
            top: 10px;
            right: 4px;
            width: 4px;
            height: 4px;
            background: #1a1a1a;
            border-radius: 50%;
            box-shadow: 10px 0 0 #1a1a1a;
        }
        .hero-girl-body {
            position: absolute;
            top: 32px;
            left: 50%;
            transform: translateX(-50%);
            width: 42px;
            height: 56px;
            background: #fff;
            border-radius: 12px 12px 8px 8px;
        }
        .hero-girl-arm {
            position: absolute;
            top: 38px;
            right: -28px;
            width: 38px;
            height: 11px;
            background: #FFD0B5;
            border-radius: 12px;
            transform: rotate(-18deg);
            transform-origin: left center;
        }
        .hero-girl-legs {
            position: absolute;
            bottom: 8px;
            left: 50%;
            transform: translateX(-50%);
            width: 36px;
            height: 20px;
            background: #fff;
            border-radius: 0 0 8px 8px;
        }

        .hero-bag {
            position: absolute;
            bottom: 40px;
            right: 8px;
            width: 32px;
            height: 36px;
            background: #FF6B35;
            border-radius: 8px 8px 10px 10px;
            opacity: 0.9;
            z-index: 1;
        }
        .hero-bag::before {
            content: '';
            position: absolute;
            top: -7px;
            left: 50%;
            transform: translateX(-50%);
            width: 14px;
            height: 9px;
            border: 2.5px solid #FF6B35;
            border-bottom: none;
            border-radius: 6px 6px 0 0;
        }
        .hero-bag-handle {
            position: absolute;
            top: -5px;
            left: 50%;
            transform: translateX(-50%);
            width: 5px;
            height: 7px;
            border: 2px solid #fff;
            border-bottom: none;
            border-radius: 4px 4px 0 0;
        }

        .hero-coin {
            position: absolute;
            width: 26px;
            height: 26px;
            background: radial-gradient(circle at 35% 35%, #FFD700, #FFA500);
            border-radius: 50%;
            border: 2px solid #E8960C;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 900;
            color: #8B5E00;
            box-shadow: 0 3px 8px rgba(0,0,0,0.18);
            z-index: 1;
        }
        .hero-coin-1 { top: 80px; right: 130px; }
        .hero-coin-2 { top: 160px; left: 24px; }
        .hero-coin-3 { top: 200px; right: 20px; }

        .hero-sparkle {
            position: absolute;
            background: rgba(255,255,255,0.35);
            border-radius: 50%;
            z-index: 0;
        }
        .hero-sparkle:nth-child(1) { top: 30px; right: 60px; width: 10px; height: 10px; }
        .hero-sparkle:nth-child(2) { top: 60px; left: 40px; width: 6px; height: 6px; }
        .hero-sparkle:nth-child(3) { top: 140px; right: 80px; width: 8px; height: 8px; }
        .hero-sparkle:nth-child(4) { bottom: 100px; left: 60px; width: 5px; height: 5px; }
        .hero-sparkle:nth-child(5) { top: 240px; right: 40px; width: 6px; height: 6px; }

        /* ───── Section ───── */
        .section-label {
            font-size: 13px;
            font-weight: 600;
            color: #aaa;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* ───── Benefits ───── */
        .benefits {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .benefit-card {
            background: #fff;
            border-radius: 20px;
            padding: 20px 18px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
            border: 1px solid rgba(0,0,0,0.04);
            min-height: 86px;
        }
        .benefit-icon {
            font-size: 28px;
            flex-shrink: 0;
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8f8f8;
            border-radius: 14px;
        }
        .benefit-content {
            flex: 1;
        }
        .benefit-title {
            font-size: 14px;
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 3px;
        }
        .benefit-desc {
            font-size: 12.5px;
            color: #999;
            line-height: 1.45;
        }

        /* ───── Google CTA ───── */
        .btn-google {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background: #fff;
            border: 1.5px solid #d4d4d4;
            border-radius: 16px;
            height: 56px;
            font-size: 15px;
            font-weight: 600;
            color: #333;
            cursor: pointer;
            text-decoration: none;
            box-shadow: 0 4px 20px rgba(0,0,0,0.07);
            transition: box-shadow 0.2s, border-color 0.2s;
            flex-shrink: 0;
        }
        .btn-google:hover {
            box-shadow: 0 8px 28px rgba(0,0,0,0.12);
            border-color: #bbb;
        }
        .btn-google svg {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }

        /* ───── Auth row ───── */
        .auth-row {
            display: flex;
            gap: 12px;
            flex-shrink: 0;
        }
        .auth-row a {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 14px;
            border-radius: 16px;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            transition: opacity 0.2s;
        }
        .btn-login {
            background: #FF6B35;
            color: #fff;
            border: none;
        }
        .btn-login:hover {
            opacity: 0.9;
        }
        .btn-register {
            background: #fff;
            color: #FF6B35;
            border: 1.5px solid #FF6B35;
        }
        .btn-register:hover {
            background: #fff5f0;
        }

        /* ───── Responsive ───── */
        @media (min-width: 481px) {
            .hero {
                border-radius: 32px;
                margin: 0;
                width: 100%;
            }
        }
        @media (min-width: 600px) {
            .container {
                padding: 0 32px 32px;
            }
            .hero {
                margin: 0 -12px;
                padding: 0 32px;
                width: calc(100% + 24px);
            }
        }
        @media (min-width: 1024px) {
            body {
                padding: 40px 0;
            }
            .hero {
                height: 360px;
                margin: 0;
                width: 100%;
            }
            .hero-title {
                font-size: 52px;
            }
        }

        /* iPhone 12 (390x844) – hero ~35-40% of height */
        @media (max-height: 850px) {
            .hero {
                height: 310px;
            }
            .hero-title {
                font-size: 36px;
            }
            .hero-sub {
                font-size: 14px;
            }
            .container {
                gap: 16px;
            }
        }
        @media (max-height: 740px) {
            .hero {
                height: 270px;
            }
            .hero-title {
                font-size: 30px;
            }
            .hero-sub {
                font-size: 13px;
            }
            .hero-badge {
                font-size: 11px;
                padding: 6px 14px;
                margin-top: 12px;
            }
            .benefit-card {
                min-height: 74px;
                padding: 14px 16px;
            }
            .btn-google {
                height: 50px;
                font-size: 14px;
            }
            .auth-row a {
                padding: 12px;
                font-size: 14px;
            }
            .container {
                gap: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Hero -->
        <div class="hero">
            <div class="hero-sparkle"></div>
            <div class="hero-sparkle"></div>
            <div class="hero-sparkle"></div>
            <div class="hero-sparkle"></div>
            <div class="hero-sparkle"></div>

            <div class="hero-coin hero-coin-1">$</div>
            <div class="hero-coin hero-coin-2">%</div>
            <div class="hero-coin hero-coin-3">$</div>

            <div class="hero-content">
                <div class="hero-title">Hoàn Tiền</div>
                <div class="hero-sub">Mua sắm thông minh, hoàn tiền tối đa.</div>
                <div class="hero-badge">
                    <span class="hero-badge-fire">🔥</span>
                    Hoàn tiền đến 15% tại Shopee, Lazada, TikTok Shop
                </div>
            </div>

            <div class="hero-phone">
                <div class="hero-phone-screen"></div>
            </div>

            <div class="hero-girl">
                <div class="hero-girl-head"></div>
                <div class="hero-girl-body"></div>
                <div class="hero-girl-arm"></div>
                <div class="hero-girl-legs"></div>
            </div>

            <div class="hero-bag">
                <div class="hero-bag-handle"></div>
            </div>
        </div>

        <!-- Benefits -->
        <div class="benefits">
            <div class="benefit-card">
                <span class="benefit-icon">💰</span>
                <div class="benefit-content">
                    <div class="benefit-title">Hoàn tiền đến 15%</div>
                    <div class="benefit-desc">Nhận tiền hoàn sau mỗi đơn hàng thành công.</div>
                </div>
            </div>
            <div class="benefit-card">
                <span class="benefit-icon">⚡</span>
                <div class="benefit-content">
                    <div class="benefit-title">Tạo link hoàn tiền nhanh</div>
                    <div class="benefit-desc">Dán link sản phẩm và nhận link chỉ trong vài giây.</div>
                </div>
            </div>
            <div class="benefit-card">
                <span class="benefit-icon">🛡</span>
                <div class="benefit-content">
                    <div class="benefit-title">Theo dõi minh bạch</div>
                    <div class="benefit-desc">Kiểm tra đơn hàng và hoa hồng dễ dàng.</div>
                </div>
            </div>
        </div>

        <!-- Google CTA -->
        <a href="#" class="btn-google" onclick="return false;">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/>
                <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
            </svg>
            Đăng nhập bằng Google
        </a>

        <!-- Login / Register -->
        @if (Route::has('login'))
            <div class="auth-row">
                <a href="{{ route('login') }}" class="btn-login">Đăng nhập</a>
                @if (Route::has('register'))
                    <a href="{{ route('register') }}" class="btn-register">Đăng ký</a>
                @endif
            </div>
        @endif
    </div>
</body>
</html>
