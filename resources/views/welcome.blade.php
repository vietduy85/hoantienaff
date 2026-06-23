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
            margin: 0 auto;
            padding: 12px 12px 28px;
            display: flex;
            flex-direction: column;
            gap: 0;
        }

        /* ───── Hero ───── */
        .hero {
            width: 100%;
            height: 250px;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 8px 28px rgba(255, 107, 53, 0.18);
            background: linear-gradient(135deg, #FF6B35, #FF8F5E);
            flex-shrink: 0;
        }
        .hero img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }

        /* ───── Badge ───── */
        .hero-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #fff;
            padding: 12px 18px;
            border-radius: 14px;
            font-size: 13px;
            font-weight: 600;
            color: #333;
            margin-top: 16px;
            width: 100%;
            box-shadow: 0 4px 20px rgba(255, 107, 53, 0.12);
            border: 1px solid rgba(255, 107, 53, 0.1);
            flex-shrink: 0;
        }
        .hero-badge-fire {
            font-size: 20px;
            flex-shrink: 0;
        }
        .hero-badge-text {
            flex: 1;
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
            font-size: 18px;
            font-weight: 700;
            color: #333;
            cursor: pointer;
            text-decoration: none;
            margin-top: 20px;
            box-shadow: 0 6px 24px rgba(0,0,0,0.08);
            transition: box-shadow 0.2s, border-color 0.2s;
            flex-shrink: 0;
        }
        .btn-google:hover {
            box-shadow: 0 8px 32px rgba(0,0,0,0.12);
            border-color: #bbb;
        }
        .btn-google svg {
            width: 22px;
            height: 22px;
            flex-shrink: 0;
        }

        /* ───── Auth row ───── */
        .auth-row {
            display: flex;
            gap: 12px;
            margin-top: 12px;
            flex-shrink: 0;
        }
        .auth-row a {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            height: 52px;
            border-radius: 16px;
            font-size: 16px;
            font-weight: 700;
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

        /* ───── Benefits ───── */
        .benefits {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 20px;
        }
        .benefit-card {
            background: #fff;
            border-radius: 20px;
            padding: 16px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
            border: 1px solid rgba(0,0,0,0.04);
            min-height: 80px;
        }
        .benefit-icon {
            font-size: 24px;
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
            font-size: 16px;
            font-weight: 700;
            color: #1a1a1a;
            margin-bottom: 3px;
        }
        .benefit-desc {
            font-size: 13px;
            color: #999;
            line-height: 1.45;
        }

        /* ───── Responsive ───── */
        @media (min-width: 481px) {
            .container {
                padding: 20px 20px 32px;
            }
            .hero {
                height: 260px;
            }
        }
        @media (min-width: 1024px) {
            body {
                padding: 40px 0;
            }
            .hero {
                height: 280px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Hero -->
        <div class="hero">
            <img src="{{ asset('images/hero-cashback.png') }}" alt="Hoàn Tiền">
        </div>

        <!-- Badge -->
        <div class="hero-badge">
            <span class="hero-badge-fire">🔥</span>
            <span class="hero-badge-text">Hoàn tiền đến 15% tại Shopee, Lazada, TikTok Shop</span>
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
    </div>
</body>
</html>
