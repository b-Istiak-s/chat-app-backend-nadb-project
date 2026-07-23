<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Subscribe to Chat App and download the Android app.">
    <title>Chat App — Connect Simply</title>
    <style>
        :root {
            color-scheme: dark;
            --background: #0b1020;
            --surface: rgba(20, 29, 55, 0.72);
            --text: #f8fafc;
            --muted: #a9b4cb;
            --accent: #8b5cf6;
            --accent-dark: #6d28d9;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            padding: 24px;
            overflow: hidden;
            color: var(--text);
            background:
                radial-gradient(circle at 15% 20%, rgba(139, 92, 246, 0.25), transparent 34%),
                radial-gradient(circle at 85% 80%, rgba(59, 130, 246, 0.2), transparent 34%),
                var(--background);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .card {
            width: min(100%, 620px);
            padding: clamp(36px, 8vw, 72px) clamp(24px, 8vw, 64px);
            text-align: center;
            background: var(--surface);
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 28px;
            box-shadow: 0 28px 90px rgba(0, 0, 0, 0.35);
            backdrop-filter: blur(18px);
        }

        .icon {
            width: 76px;
            height: 76px;
            margin: 0 auto 28px;
            display: grid;
            place-items: center;
            border-radius: 22px;
            color: white;
            background: linear-gradient(135deg, var(--accent), #3b82f6);
            font-size: 34px;
            font-weight: 800;
            box-shadow: 0 12px 30px rgba(124, 58, 237, 0.35);
        }

        h1 {
            margin: 0;
            font-size: clamp(2.25rem, 7vw, 4rem);
            line-height: 1.05;
            letter-spacing: -0.05em;
        }

        p.lead {
            max-width: 430px;
            margin: 20px auto 32px;
            color: var(--muted);
            font-size: 1.1rem;
            line-height: 1.7;
        }

        .download-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            min-width: 210px;
            padding: 15px 24px;
            color: white;
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            border-radius: 999px;
            font-size: 1rem;
            font-weight: 700;
            text-decoration: none;
            transition: transform 160ms ease, box-shadow 160ms ease;
            box-shadow: 0 12px 24px rgba(109, 40, 217, 0.32);
        }

        .download-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 30px rgba(109, 40, 217, 0.45);
        }

        .download-button:focus-visible {
            outline: 3px solid rgba(196, 181, 253, 0.9);
            outline-offset: 4px;
        }

        .note {
            margin: 18px 0 0;
            color: #7f8ba5;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <main class="card">
        <div class="icon" aria-hidden="true">C</div>
        <h1>Chat App</h1>
        <p class="lead">
            Stay connected with the people who matter. Subscribe to unlock the Android app and AI chat —
            simple, fast, and ready to use wherever you are.
        </p>

        @auth('web')
            <a class="download-button" href="{{ route('dashboard.index') }}">
                <span aria-hidden="true">→</span>
                Go to dashboard
            </a>
            <p class="note">Signed in as +880{{ auth('web')->user()->phone }}</p>
        @else
            <a class="download-button" href="{{ route('login') }}">
                <span aria-hidden="true">↓</span>
                Subscribe and download
            </a>
            <p class="note">Android app · Subscription required · Verify via OTP</p>
        @endauth
    </main>
</body>
</html>
