<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('components.pwa-meta')

    <title>Đăng nhập - Hoàn Tiền Aff</title>
    <meta name="robots" content="noindex, nofollow">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=be-vietnam-pro:400,500,600,700,800&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/in-app-browser.js'])

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --brand: #FF6B35;
            --brand-dark: #E85A28;
            --ink: #1a1a1a;
            --muted: #6b7280;
            --line: #e5e7eb;
            --danger: #dc2626;
        }

        body {
            font-family: 'Be Vietnam Pro', system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;
            background: linear-gradient(160deg, #FFF6F1 0%, #FFEDE3 100%);
            color: var(--ink);
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: calc(16px + env(safe-area-inset-top, 0px)) 16px calc(16px + env(safe-area-inset-bottom, 0px));
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            background: #fff;
            border-radius: 22px;
            box-shadow: 0 12px 40px rgba(255, 107, 53, 0.14);
            padding: 28px 22px 26px;
        }

        .brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 20px;
        }
        .brand-logo { font-size: 26px; line-height: 1; }
        .brand-name { font-weight: 800; font-size: 18px; color: var(--brand); }

        .login-title {
            font-size: 26px;
            font-weight: 800;
            text-align: center;
            letter-spacing: -0.01em;
        }
        .login-sub {
            margin-top: 8px;
            font-size: 14px;
            color: var(--muted);
            text-align: center;
        }
        .login-sub a {
            color: var(--brand);
            font-weight: 600;
            text-decoration: none;
        }
        .login-sub a:hover { text-decoration: underline; }

        .alert {
            margin-top: 18px;
            padding: 11px 14px;
            border-radius: 12px;
            font-size: 14px;
            line-height: 1.45;
        }
        .alert-error { background: #FEF2F2; border: 1px solid #FECACA; color: #B91C1C; }
        .alert-status { background: #ECFDF5; border: 1px solid #A7F3D0; color: #047857; }

        .form { margin-top: 22px; }
        .field { margin-bottom: 16px; }

        .field label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 7px;
        }

        .control { position: relative; }

        .input {
            width: 100%;
            height: 52px;
            padding: 0 14px;
            font-size: 16px;
            font-family: inherit;
            color: var(--ink);
            background: #fff;
            border: 1.5px solid var(--line);
            border-radius: 14px;
            outline: none;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .input::placeholder { color: #9ca3af; }
        .input:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.16);
        }
        .input.has-error { border-color: #fca5a5; }
        .input.has-error:focus { box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.14); }

        .control .toggle {
            position: absolute;
            top: 50%;
            right: 8px;
            transform: translateY(-50%);
            width: 38px;
            height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: none;
            background: transparent;
            color: #9ca3af;
            cursor: pointer;
            border-radius: 10px;
        }
        .control .toggle:hover { color: #4b5563; }
        .control .toggle:focus-visible {
            outline: none;
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.25);
        }
        .control .toggle svg { width: 22px; height: 22px; }
        .password-input { padding-right: 50px; }

        .error-text {
            margin-top: 6px;
            font-size: 13px;
            color: var(--danger);
        }

        .row-between {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: 4px 0 20px;
        }

        .remember {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: #374151;
            cursor: pointer;
            user-select: none;
        }
        .remember input {
            width: 18px;
            height: 18px;
            accent-color: var(--brand);
            cursor: pointer;
        }

        .forgot {
            font-size: 14px;
            font-weight: 600;
            color: var(--brand);
            text-decoration: none;
            white-space: nowrap;
        }
        .forgot:hover { text-decoration: underline; }

        .btn-primary {
            width: 100%;
            height: 52px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: none;
            border-radius: 14px;
            background: var(--brand);
            color: #fff;
            font-family: inherit;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 6px 20px rgba(255, 107, 53, 0.28);
            transition: background 0.15s, opacity 0.15s;
        }
        .btn-primary:hover { background: var(--brand-dark); }
        .btn-primary:active { transform: translateY(1px); }
        .btn-primary:disabled { opacity: 0.65; cursor: not-allowed; }

        .spinner {
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255, 255, 255, 0.5);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
            display: none;
        }
        .btn-primary.is-loading .spinner { display: inline-block; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 22px 0 16px;
            color: #9ca3af;
            font-size: 13px;
            white-space: nowrap;
        }
        .divider::before,
        .divider::after {
            content: "";
            flex: 1;
            height: 1px;
            background: var(--line);
        }

        .btn-google {
            width: 100%;
            height: 52px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background: #fff;
            border: 1.5px solid #d1d5db;
            border-radius: 14px;
            font-size: 15px;
            font-weight: 600;
            color: #333;
            text-decoration: none;
            transition: background 0.15s, border-color 0.15s, box-shadow 0.15s;
        }
        .btn-google:hover {
            background: #f9fafb;
            border-color: #bbb;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.06);
        }
        .btn-google svg { width: 22px; height: 22px; flex-shrink: 0; }

        /* ───── In-app browser (Zalo/Facebook...) warning ───── */
        .inapp-overlay {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: rgba(0, 0, 0, 0.75);
            -webkit-backdrop-filter: blur(8px);
            backdrop-filter: blur(8px);
            z-index: 2147483647;
        }
        .inapp-overlay.show { display: flex; }
        .inapp-box {
            background: #1f2937;
            border-radius: 20px;
            padding: 26px;
            max-width: 360px;
            width: 100%;
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        .inapp-icon { font-size: 56px; line-height: 1; margin-bottom: 8px; }
        .inapp-text {
            color: #fff;
            font-weight: 600;
            font-size: 15px;
            line-height: 1.6;
            margin-bottom: 22px;
        }
        .inapp-open {
            width: 100%;
            height: 52px;
            background: #10b981;
            color: #fff;
            font-family: inherit;
            font-weight: 700;
            font-size: 15px;
            border: none;
            border-radius: 14px;
            cursor: pointer;
            margin-bottom: 12px;
        }
        .inapp-close {
            width: 100%;
            height: 48px;
            background: rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.85);
            font-family: inherit;
            font-weight: 600;
            font-size: 15px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            cursor: pointer;
        }

        @media (min-width: 480px) {
            .login-card { padding: 36px 32px 32px; }
            .login-title { font-size: 28px; }
        }
    </style>
</head>
<body>
    <main class="login-card" role="main">
        <div class="brand">
            <span class="brand-logo" aria-hidden="true">💰</span>
            <span class="brand-name">Hoàn Tiền Aff</span>
        </div>

        <h1 class="login-title">Đăng nhập</h1>
        <p class="login-sub">
            Bạn chưa có tài khoản?
            <a href="{{ route('register') }}">Đăng ký</a>
        </p>

        @if (session('error'))
            <div class="alert alert-error" role="alert">{{ session('error') }}</div>
        @endif

        @if (session('status'))
            <div class="alert alert-status" role="status">{{ session('status') }}</div>
        @endif

        <form class="form" method="POST" action="{{ route('login') }}" id="login-form">
            @csrf

            <div class="field">
                <label for="login">Username / Email</label>
                <div class="control">
                    <input
                        id="login"
                        class="input @error('login') has-error @enderror"
                        type="text"
                        name="login"
                        value="{{ old('login') }}"
                        placeholder="Nhập Username/Email"
                        required
                        autofocus
                        autocomplete="username"
                        aria-describedby="login-error"
                    >
                </div>
                @error('login')
                    <p class="error-text" id="login-error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="password">Mật khẩu</label>
                <div class="control">
                    <input
                        id="password"
                        class="input password-input @error('password') has-error @enderror"
                        type="password"
                        name="password"
                        placeholder="Nhập mật khẩu"
                        required
                        autocomplete="current-password"
                        aria-describedby="password-error"
                    >
                    <button type="button" class="toggle" id="toggle-password"
                            aria-label="Hiện mật khẩu" aria-pressed="false">
                        <svg id="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                        <svg id="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none">
                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                            <line x1="1" y1="1" x2="23" y2="23"/>
                        </svg>
                    </button>
                </div>
                @error('password')
                    <p class="error-text" id="password-error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="row-between">
                <label class="remember" for="remember_me">
                    <input id="remember_me" type="checkbox" name="remember" value="1" @checked(old('remember'))>
                    <span>Ghi nhớ đăng nhập</span>
                </label>

                @if (Route::has('password.request'))
                    <a class="forgot" href="{{ route('password.request') }}">Quên mật khẩu?</a>
                @endif
            </div>

            <button type="submit" class="btn-primary" id="login-submit">
                <span class="spinner" aria-hidden="true"></span>
                <span>Đăng nhập</span>
            </button>
        </form>

        <div class="divider">Hoặc đăng nhập bằng Google</div>

        <a href="{{ route('google.redirect') }}" id="google-login" class="btn-google" aria-label="Đăng nhập bằng Google">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/>
                <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
            </svg>
            <span>Đăng nhập bằng Google</span>
        </a>
    </main>

    <div class="inapp-overlay" id="inapp-overlay" role="dialog" aria-modal="true" aria-label="Thông báo trình duyệt">
        <div class="inapp-box">
            <div class="inapp-icon" aria-hidden="true">🌐</div>
            <div class="inapp-text">
                Trình duyệt tích hợp của Zalo, Facebook, Messenger và một số ứng dụng khác không hỗ trợ đăng nhập bằng Google.<br><br>
                Vui lòng mở trang bằng Safari, Chrome hoặc trình duyệt mặc định để tiếp tục.
            </div>
            <button type="button" class="inapp-open" id="inapp-open" aria-label="Mở bằng trình duyệt">🌐 Mở bằng trình duyệt</button>
            <button type="button" class="inapp-close" id="inapp-close" aria-label="Đóng">Đóng</button>
        </div>
    </div>

    <script>
        (function () {
            var input = document.getElementById('password');
            var toggle = document.getElementById('toggle-password');
            var eyeOpen = document.getElementById('eye-open');
            var eyeClosed = document.getElementById('eye-closed');

            if (toggle && input) {
                toggle.addEventListener('click', function () {
                    var show = input.type === 'password';
                    input.type = show ? 'text' : 'password';
                    toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
                    toggle.setAttribute('aria-label', show ? 'Ẩn mật khẩu' : 'Hiện mật khẩu');
                    eyeOpen.style.display = show ? 'none' : '';
                    eyeClosed.style.display = show ? '' : 'none';
                });
            }

            var form = document.getElementById('login-form');
            var submit = document.getElementById('login-submit');
            if (form && submit) {
                form.addEventListener('submit', function () {
                    submit.classList.add('is-loading');
                    submit.disabled = true;
                });
            }

            // Google OAuth bị Google chặn trong webview của Zalo/Facebook/Messenger.
            // Giữ nguyên hành vi cảnh báo như trang chủ trước đây (đọc window.__inAppBrowser).
            var overlay = document.getElementById('inapp-overlay');
            var google = document.getElementById('google-login');
            if (overlay && google) {
                google.addEventListener('click', function (e) {
                    if (window.__inAppBrowser) {
                        e.preventDefault();
                        overlay.classList.add('show');
                    }
                });
                var openBtn = document.getElementById('inapp-open');
                var closeBtn = document.getElementById('inapp-close');
                if (openBtn) {
                    openBtn.addEventListener('click', function () {
                        if (typeof window.__openInBrowser === 'function') {
                            window.__openInBrowser(window.location.href);
                        }
                    });
                }
                if (closeBtn) {
                    closeBtn.addEventListener('click', function () {
                        overlay.classList.remove('show');
                    });
                }
            }
        })();
    </script>
</body>
</html>
