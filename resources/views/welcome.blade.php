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
            padding: 0 20px;
            margin: 0 auto;
        }

        /* Header */
        header {
            width: 100%;
            text-align: center;
            padding: 40px 0 24px;
        }
        .logo {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 60px;
            height: 60px;
            background: #FF6B35;
            border-radius: 16px;
            margin-bottom: 16px;
            box-shadow: 0 4px 12px rgba(255, 107, 53, 0.3);
        }
        .logo span {
            font-size: 28px;
            font-weight: 800;
            color: #fff;
        }
        h1 {
            font-size: 32px;
            font-weight: 800;
            color: #1a1a1a;
            margin-bottom: 6px;
        }
        .subtitle {
            font-size: 15px;
            font-weight: 400;
            color: #888;
        }

        /* Hero */
        .hero {
            width: 100%;
            height: 260px;
            background: linear-gradient(135deg, #FF6B35 0%, #FF8F5E 50%, #fff0e8 100%);
            border-radius: 24px;
            margin-bottom: 28px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(255, 107, 53, 0.15);
        }
        .hero-inner {
            width: 100%;
            height: 100%;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Phone illustration */
        .hero-phone {
            position: absolute;
            width: 80px;
            height: 140px;
            background: #fff;
            border-radius: 16px;
            bottom: 30px;
            left: 60px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
        }
        .hero-phone::before {
            content: '';
            position: absolute;
            top: 8px;
            left: 50%;
            transform: translateX(-50%);
            width: 30px;
            height: 4px;
            background: #e0e0e0;
            border-radius: 4px;
        }
        .hero-phone-screen {
            position: absolute;
            top: 18px;
            left: 8px;
            right: 8px;
            bottom: 12px;
            background: linear-gradient(180deg, #FF6B35 0%, #FF8F5E 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .hero-phone-screen::after {
            content: '$';
            font-size: 24px;
            font-weight: 800;
            color: #fff;
        }

        /* Girl silhouette */
        .hero-girl {
            position: absolute;
            width: 100px;
            height: 150px;
            bottom: 20px;
            right: 70px;
        }
        .hero-girl-head {
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 40px;
            height: 40px;
            background: #FFD0B5;
            border-radius: 50%;
        }
        .hero-girl-head::after {
            content: '';
            position: absolute;
            top: -8px;
            left: -4px;
            width: 48px;
            height: 20px;
            background: #1a1a1a;
            border-radius: 20px 20px 0 0;
        }
        .hero-girl-body {
            position: absolute;
            top: 36px;
            left: 50%;
            transform: translateX(-50%);
            width: 48px;
            height: 60px;
            background: #fff;
            border-radius: 12px 12px 8px 8px;
        }
        .hero-girl-arm {
            position: absolute;
            top: 42px;
            right: -30px;
            width: 40px;
            height: 12px;
            background: #FFD0B5;
            border-radius: 12px;
            transform: rotate(-20deg);
            transform-origin: left center;
        }
        .hero-girl-legs {
            position: absolute;
            bottom: 8px;
            left: 50%;
            transform: translateX(-50%);
            width: 40px;
            height: 24px;
            background: #fff;
            border-radius: 0 0 8px 8px;
        }

        /* Shopping bag */
        .hero-bag {
            position: absolute;
            bottom: 50px;
            right: 30px;
            width: 36px;
            height: 40px;
            background: #FF6B35;
            border-radius: 8px 8px 10px 10px;
            opacity: 0.9;
        }
        .hero-bag::before {
            content: '';
            position: absolute;
            top: -8px;
            left: 50%;
            transform: translateX(-50%);
            width: 16px;
            height: 10px;
            border: 3px solid #FF6B35;
            border-bottom: none;
            border-radius: 8px 8px 0 0;
        }
        .hero-bag-handle {
            position: absolute;
            top: -6px;
            left: 50%;
            transform: translateX(-50%);
            width: 6px;
            height: 8px;
            border: 2px solid #fff;
            border-bottom: none;
            border-radius: 4px 4px 0 0;
        }

        /* Coins */
        .hero-coin {
            position: absolute;
            width: 28px;
            height: 28px;
            background: radial-gradient(circle at 35% 35%, #FFD700, #FFA500);
            border-radius: 50%;
            border: 2px solid #E8960C;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 800;
            color: #8B5E00;
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
        }
        .hero-coin-1 {
            top: 30px;
            left: 140px;
        }
        .hero-coin-2 {
            top: 70px;
            right: 140px;
        }
        .hero-coin-3 {
            bottom: 120px;
            left: 180px;
        }
        .hero-coin-4 {
            top: 110px;
            right: 60px;
        }

        /* Floating elements */
        .hero-sparkle {
            position: absolute;
            width: 8px;
            height: 8px;
            background: #fff;
            border-radius: 50%;
            opacity: 0.6;
        }
        .hero-sparkle:nth-child(1) { top: 20px; left: 30px; }
        .hero-sparkle:nth-child(2) { top: 40px; right: 40px; width: 6px; height: 6px; }
        .hero-sparkle:nth-child(3) { bottom: 60px; left: 20px; width: 5px; height: 5px; }

        /* Benefits */
        .benefits {
            display: flex;
            flex-direction: column;
            gap: 16px;
            margin-bottom: 32px;
        }
        .benefit-card {
            background: #fff;
            border-radius: 20px;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.05);
            border: 1px solid rgba(0,0,0,0.04);
            min-height: 88px;
            width: 100%;
        }
        .benefit-icon {
            font-size: 32px;
            flex-shrink: 0;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
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
            font-size: 12px;
            color: #999;
            line-height: 1.4;
        }

        /* Auth */
        .auth-buttons {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
        }
        .auth-buttons a {
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

        /* Google option */
        .google-label {
            text-align: center;
            font-size: 13px;
            color: #bbb;
            margin-bottom: 12px;
        }
        .btn-google-sm {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 14px;
            height: 48px;
            font-size: 14px;
            font-weight: 500;
            color: #555;
            cursor: pointer;
            text-decoration: none;
            transition: box-shadow 0.2s;
        }
        .btn-google-sm:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .btn-google-sm svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }

        /* Footer */
        footer {
            margin-top: 40px;
            padding-bottom: 40px;
            text-align: center;
        }
        footer p {
            font-size: 12px;
            color: #aaa;
            line-height: 1.5;
        }

        /* Responsive */
        @media (min-width: 600px) {
            .container {
                padding: 0 32px;
            }
        }
        @media (min-width: 1024px) {
            body {
                padding: 40px 0;
            }
            .container {
                padding: 0 20px;
            }
            .hero {
                height: 280px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="logo">
                <span>H</span>
            </div>
            <h1>Hoàn Tiền</h1>
            <p class="subtitle">Mua sắm thông minh, hoàn tiền tối đa.</p>
        </header>

        <div class="hero">
            <div class="hero-inner">
                <div class="hero-sparkle"></div>
                <div class="hero-sparkle"></div>
                <div class="hero-sparkle"></div>

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

        <div class="benefits">
            <div class="benefit-card">
                <span class="benefit-icon">💰</span>
                <div class="benefit-content">
                    <div class="benefit-title">Hoàn tiền lên đến 15%</div>
                    <div class="benefit-desc">Nhận tiền hoàn lại sau mỗi đơn hàng thành công.</div>
                </div>
            </div>
            <div class="benefit-card">
                <span class="benefit-icon">⚡</span>
                <div class="benefit-content">
                    <div class="benefit-title">Tạo link hoàn tiền nhanh chóng</div>
                    <div class="benefit-desc">Chỉ cần dán link sản phẩm và nhận link hoàn tiền.</div>
                </div>
            </div>
            <div class="benefit-card">
                <span class="benefit-icon">🛡</span>
                <div class="benefit-content">
                    <div class="benefit-title">Theo dõi đơn hàng minh bạch</div>
                    <div class="benefit-desc">Kiểm tra trạng thái đơn hàng và hoa hồng dễ dàng.</div>
                </div>
            </div>
        </div>

        @if (Route::has('login'))
            <div class="auth-buttons">
                <a href="{{ route('login') }}" class="btn-login">Đăng nhập</a>
                @if (Route::has('register'))
                    <a href="{{ route('register') }}" class="btn-register">Đăng ký</a>
                @endif
            </div>

            <div class="google-label">Hoặc tiếp tục với Google</div>

            <a href="#" class="btn-google-sm" onclick="return false;">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/>
                    <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                    <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                    <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                </svg>
                Continue with Google
            </a>
        @endif

        <footer>
            <p>Email của bạn chỉ được dùng để theo dõi hoàn tiền.</p>
        </footer>
    </div>
</body>
</html>
