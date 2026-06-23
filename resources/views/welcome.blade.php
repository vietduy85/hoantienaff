<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Hoàn Tiền') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .container {
            width: 100%;
            max-width: 480px;
            padding: 16px 20px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        /* Hero (contains logo, title, subtitle + illustration) */
        .hero {
            width: 100%;
            height: 200px;
            background: linear-gradient(135deg, #FF6B35 0%, #FF8F5E 50%, #fff0e8 100%);
            border-radius: 24px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(255, 107, 53, 0.15);
            flex-shrink: 0;
        }
        .hero-inner {
            width: 100%;
            height: 100%;
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 0 20px;
        }
        .hero-logo {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            background: #fff;
            border-radius: 12px;
            margin-bottom: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .hero-logo span {
            font-size: 22px;
            font-weight: 800;
            color: #FF6B35;
        }
        .hero-title {
            font-size: 26px;
            font-weight: 800;
            color: #fff;
            line-height: 1.2;
            text-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .hero-sub {
            font-size: 13px;
            font-weight: 400;
            color: rgba(255,255,255,0.85);
            margin-top: 2px;
        }

        /* Phone illustration */
        .hero-phone {
            position: absolute;
            width: 64px;
            height: 112px;
            background: #fff;
            border-radius: 14px;
            bottom: 14px;
            left: 24px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
        }
        .hero-phone::before {
            content: '';
            position: absolute;
            top: 6px;
            left: 50%;
            transform: translateX(-50%);
            width: 24px;
            height: 3px;
            background: #e0e0e0;
            border-radius: 4px;
        }
        .hero-phone-screen {
            position: absolute;
            top: 14px;
            left: 6px;
            right: 6px;
            bottom: 10px;
            background: linear-gradient(180deg, #FF6B35 0%, #FF8F5E 100%);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .hero-phone-screen::after {
            content: '$';
            font-size: 20px;
            font-weight: 800;
            color: #fff;
        }

        /* Girl silhouette */
        .hero-girl {
            position: absolute;
            width: 80px;
            height: 120px;
            bottom: 8px;
            right: 28px;
        }
        .hero-girl-head {
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 32px;
            height: 32px;
            background: #FFD0B5;
            border-radius: 50%;
        }
        .hero-girl-head::after {
            content: '';
            position: absolute;
            top: -6px;
            left: -3px;
            width: 38px;
            height: 16px;
            background: #1a1a1a;
            border-radius: 20px 20px 0 0;
        }
        .hero-girl-body {
            position: absolute;
            top: 28px;
            left: 50%;
            transform: translateX(-50%);
            width: 38px;
            height: 48px;
            background: #fff;
            border-radius: 10px 10px 6px 6px;
        }
        .hero-girl-arm {
            position: absolute;
            top: 34px;
            right: -24px;
            width: 32px;
            height: 10px;
            background: #FFD0B5;
            border-radius: 10px;
            transform: rotate(-20deg);
            transform-origin: left center;
        }
        .hero-girl-legs {
            position: absolute;
            bottom: 6px;
            left: 50%;
            transform: translateX(-50%);
            width: 32px;
            height: 18px;
            background: #fff;
            border-radius: 0 0 6px 6px;
        }

        /* Shopping bag */
        .hero-bag {
            position: absolute;
            bottom: 28px;
            right: 14px;
            width: 28px;
            height: 32px;
            background: #FF6B35;
            border-radius: 6px 6px 8px 8px;
            opacity: 0.9;
        }
        .hero-bag::before {
            content: '';
            position: absolute;
            top: -6px;
            left: 50%;
            transform: translateX(-50%);
            width: 12px;
            height: 8px;
            border: 2px solid #FF6B35;
            border-bottom: none;
            border-radius: 6px 6px 0 0;
        }
        .hero-bag-handle {
            position: absolute;
            top: -4px;
            left: 50%;
            transform: translateX(-50%);
            width: 4px;
            height: 6px;
            border: 2px solid #fff;
            border-bottom: none;
            border-radius: 4px 4px 0 0;
        }

        /* Coins */
        .hero-coin {
            position: absolute;
            width: 22px;
            height: 22px;
            background: radial-gradient(circle at 35% 35%, #FFD700, #FFA500);
            border-radius: 50%;
            border: 2px solid #E8960C;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 800;
            color: #8B5E00;
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
        }
        .hero-coin-1 { top: 18px; left: 100px; }
        .hero-coin-2 { top: 50px; right: 100px; }
        .hero-coin-3 { bottom: 80px; left: 130px; }
        .hero-coin-4 { top: 80px; right: 40px; }

        /* Sparkles */
        .hero-sparkle {
            position: absolute;
            width: 6px;
            height: 6px;
            background: rgba(255,255,255,0.5);
            border-radius: 50%;
        }
        .hero-sparkle:nth-child(2) { top: 16px; left: 20px; }
        .hero-sparkle:nth-child(3) { top: 30px; right: 28px; width: 5px; height: 5px; }
        .hero-sparkle:nth-child(4) { bottom: 40px; left: 16px; width: 4px; height: 4px; }
        .hero-sparkle:nth-child(5) { bottom: 50px; right: 120px; width: 5px; height: 5px; }

        /* Benefits */
        .benefits {
            display: flex;
            flex-direction: column;
            gap: 10px;
            flex-shrink: 0;
        }
        .benefit-card {
            background: #fff;
            border-radius: 16px;
            padding: 0 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border: 1px solid rgba(0,0,0,0.04);
            height: 56px;
            width: 100%;
        }
        .benefit-icon {
            font-size: 22px;
            flex-shrink: 0;
        }
        .benefit-text {
            font-size: 13px;
            font-weight: 500;
            color: #333;
        }

        /* Google CTA - primary */
        .btn-google {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background: #fff;
            border: 1.5px solid #d0d0d0;
            border-radius: 14px;
            height: 52px;
            font-size: 14px;
            font-weight: 600;
            color: #333;
            cursor: pointer;
            text-decoration: none;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            transition: box-shadow 0.2s, border-color 0.2s;
            flex-shrink: 0;
        }
        .btn-google:hover {
            box-shadow: 0 6px 24px rgba(0,0,0,0.1);
            border-color: #bbb;
        }
        .btn-google svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }

        /* Auth row */
        .auth-row {
            display: flex;
            gap: 10px;
            flex-shrink: 0;
        }
        .auth-row a {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12px;
            border-radius: 14px;
            font-size: 14px;
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

        /* Short screens (iPhone 12, 13, 14 etc) */
        @media (max-height: 850px) {
            .container {
                padding: 12px 20px;
                gap: 10px;
            }
            .hero {
                height: 170px;
            }
            .hero-logo {
                width: 36px;
                height: 36px;
                border-radius: 10px;
                margin-bottom: 6px;
            }
            .hero-logo span {
                font-size: 18px;
            }
            .hero-title {
                font-size: 22px;
            }
            .hero-sub {
                font-size: 12px;
            }
            .benefit-card {
                height: 48px;
                border-radius: 14px;
                padding: 0 14px;
            }
            .benefit-icon {
                font-size: 18px;
            }
            .benefit-text {
                font-size: 12px;
            }
            .btn-google {
                height: 46px;
                font-size: 13px;
                border-radius: 12px;
            }
            .auth-row a {
                padding: 10px;
                border-radius: 12px;
                font-size: 13px;
            }
        }

        @media (max-height: 700px) {
            .container {
                padding: 8px 16px;
                gap: 8px;
            }
            .hero {
                height: 140px;
            }
            .hero-logo {
                width: 30px;
                height: 30px;
                border-radius: 8px;
                margin-bottom: 4px;
            }
            .hero-logo span {
                font-size: 15px;
            }
            .hero-title {
                font-size: 18px;
            }
            .hero-sub {
                font-size: 11px;
            }
            .benefits {
                gap: 6px;
            }
            .benefit-card {
                height: 42px;
                border-radius: 12px;
                padding: 0 12px;
                gap: 8px;
            }
            .benefit-icon {
                font-size: 16px;
            }
            .benefit-text {
                font-size: 11px;
            }
            .btn-google {
                height: 40px;
                font-size: 12px;
                border-radius: 10px;
            }
            .btn-google svg {
                width: 15px;
                height: 15px;
            }
            .auth-row {
                gap: 6px;
            }
            .auth-row a {
                padding: 8px;
                border-radius: 10px;
                font-size: 12px;
            }
        }

        @media (min-width: 600px) {
            .container {
                padding: 24px 32px;
            }
        }
        @media (min-width: 1024px) {
            body {
                padding: 40px 0;
            }
            .hero {
                height: 220px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Hero with branding inside -->
        <div class="hero">
            <div class="hero-inner">
                <div class="hero-sparkle"></div>
                <div class="hero-sparkle"></div>
                <div class="hero-sparkle"></div>
                <div class="hero-sparkle"></div>
                <div class="hero-sparkle"></div>

                <div class="hero-logo"><span>H</span></div>
                <div class="hero-title">Hoàn Tiền</div>
                <div class="hero-sub">Mua sắm thông minh, hoàn tiền tối đa.</div>

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

                <div class="hero-coin hero-coin-1">$</div>
                <div class="hero-coin hero-coin-2">%</div>
                <div class="hero-coin hero-coin-3">$</div>
                <div class="hero-coin hero-coin-4">%</div>
            </div>
        </div>

        <!-- Benefits -->
        <div class="benefits">
            <div class="benefit-card">
                <span class="benefit-icon">💰</span>
                <span class="benefit-text">Hoàn tiền đến 15%</span>
            </div>
            <div class="benefit-card">
                <span class="benefit-icon">⚡</span>
                <span class="benefit-text">Tạo link hoàn tiền nhanh</span>
            </div>
            <div class="benefit-card">
                <span class="benefit-icon">🛡</span>
                <span class="benefit-text">Theo dõi đơn hàng minh bạch</span>
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
