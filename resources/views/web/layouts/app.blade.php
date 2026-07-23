<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Chat App — subscribe, manage your subscription, and download the Android app.">
    <title>{{ $title ?? 'Chat App Dashboard' }}</title>
    <style>
        :root {
            color-scheme: light dark;
            --bg: #0b1020;
            --surface: rgba(20, 29, 55, 0.72);
            --surface-solid: #ffffff;
            --text: #f8fafc;
            --text-muted: #a9b4cb;
            --accent: #8b5cf6;
            --accent-dark: #6d28d9;
            --danger: #ef4444;
            --success: #10b981;
            --border: rgba(148, 163, 184, 0.25);
        }

        @media (prefers-color-scheme: light) {
            :root {
                --bg: #f5f6fa;
                --surface: rgba(255, 255, 255, 0.92);
                --surface-solid: #ffffff;
                --text: #0f172a;
                --text-muted: #475569;
                --border: rgba(15, 23, 42, 0.1);
            }
        }

        * { box-sizing: border-box; }

        body {
            min-height: 100vh;
            margin: 0;
            color: var(--text);
            background:
                radial-gradient(circle at 15% 20%, rgba(139, 92, 246, 0.18), transparent 34%),
                radial-gradient(circle at 85% 80%, rgba(59, 130, 246, 0.15), transparent 34%),
                var(--bg);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 32px;
            border-bottom: 1px solid var(--border);
            backdrop-filter: blur(12px);
            background: var(--surface);
        }

        .topbar .brand {
            display: flex;
            gap: 12px;
            align-items: center;
            text-decoration: none;
            color: var(--text);
            font-weight: 700;
        }

        .topbar .brand-mark {
            width: 32px;
            height: 32px;
            display: grid;
            place-items: center;
            border-radius: 9px;
            color: white;
            background: linear-gradient(135deg, var(--accent), #3b82f6);
            font-size: 16px;
            font-weight: 800;
        }

        .topbar nav a {
            color: var(--text-muted);
            text-decoration: none;
            margin-left: 22px;
            font-weight: 500;
        }

        .topbar nav a:hover { color: var(--text); }

        .topbar .signed-in {
            color: var(--text-muted);
            margin-right: 12px;
            font-size: 0.9rem;
        }

        .container {
            max-width: 760px;
            margin: 0 auto;
            padding: 32px 20px 64px;
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 32px;
            box-shadow: 0 18px 60px rgba(0, 0, 0, 0.25);
            backdrop-filter: blur(14px);
        }

        .card + .card { margin-top: 22px; }

        h1, h2, h3 { letter-spacing: -0.02em; }

        h1 { font-size: 1.8rem; margin: 0 0 8px; }
        h2 { font-size: 1.2rem; margin: 0 0 12px; }

        .muted { color: var(--text-muted); }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .badge-success { background: rgba(16, 185, 129, 0.16); color: #6ee7b7; }
        .badge-warning { background: rgba(245, 158, 11, 0.16); color: #fcd34d; }
        .badge-muted   { background: rgba(148, 163, 184, 0.16); color: #cbd5e1; }

        form { margin: 0; }

        label {
            display: block;
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 6px;
            font-weight: 600;
        }

        input[type="text"], input[type="tel"], input[type="number"] {
            width: 100%;
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: rgba(15, 23, 42, 0.45);
            color: var(--text);
            font-size: 1rem;
            font-family: inherit;
        }

        input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.25);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 22px;
            border-radius: 10px;
            border: 0;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            font-family: inherit;
            transition: transform 120ms ease, box-shadow 120ms ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            color: white;
            box-shadow: 0 10px 24px rgba(109, 40, 217, 0.32);
        }

        .btn-primary:hover { transform: translateY(-1px); }

        .btn-secondary {
            background: rgba(148, 163, 184, 0.18);
            color: var(--text);
        }

        .btn-danger {
            background: rgba(239, 68, 68, 0.18);
            color: #fca5a5;
        }

        .flash {
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .flash-success { background: rgba(16, 185, 129, 0.12); color: #6ee7b7; border: 1px solid rgba(16, 185, 129, 0.3); }
        .flash-error   { background: rgba(239, 68, 68, 0.12); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.3); }

        .alert {
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 14px;
            background: rgba(239, 68, 68, 0.1);
            color: #fca5a5;
            font-size: 0.9rem;
        }

        .field-error {
            color: #fca5a5;
            font-size: 0.85rem;
            margin-top: 6px;
        }

        .actions { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 18px; }

        .status-card {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 16px;
            align-items: center;
        }

        .meta {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .meta dt { font-weight: 600; color: var(--text); }
        .meta dd { margin: 2px 0 12px; color: var(--text-muted); }

        /* ── Pending-payment screen ── */
        .pending {
            border-color: rgba(245, 158, 11, 0.45);
            background:
                linear-gradient(135deg, rgba(245, 158, 11, 0.12), rgba(245, 158, 11, 0.02) 60%),
                var(--surface);
        }

        .pending-head {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 6px;
        }

        .pending-spinner {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: 3px solid rgba(245, 158, 11, 0.25);
            border-top-color: #f59e0b;
            animation: pending-spin 0.9s linear infinite;
            flex-shrink: 0;
        }

        @keyframes pending-spin {
            to { transform: rotate(360deg); }
        }

        .pending-steps {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin: 18px 0 4px;
        }

        .pending-step {
            padding: 12px 14px;
            border-radius: 12px;
            background: rgba(245, 158, 11, 0.06);
            border: 1px solid rgba(245, 158, 11, 0.18);
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .pending-step .step-num {
            display: inline-block;
            width: 22px;
            height: 22px;
            line-height: 22px;
            text-align: center;
            border-radius: 50%;
            background: rgba(245, 158, 11, 0.18);
            color: #fcd34d;
            font-weight: 700;
            font-size: 0.75rem;
            margin-right: 8px;
        }

        @media (max-width: 540px) {
            .pending-steps { grid-template-columns: 1fr; }
        }

        @media (prefers-reduced-motion: reduce) {
            .pending-spinner { animation: none; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <a class="brand" href="{{ route('landing') }}">
            <span class="brand-mark">C</span>
            <span>Chat App</span>
        </a>
        <nav>
            @auth('web')
                <span class="signed-in">{{ auth('web')->user()->phone }}</span>
                <a href="{{ route('dashboard.index') }}">Dashboard</a>
                <form action="{{ route('logout') }}" method="POST" style="display:inline;">
                    @csrf
                    <button type="submit" class="btn btn-secondary" style="padding:6px 14px;font-size:0.85rem;">Log out</button>
                </form>
            @else
                <a href="{{ route('landing') }}">Home</a>
                <a href="{{ route('login') }}">Sign in</a>
            @endauth
        </nav>
    </header>

    <main class="container">
        @include('web.partials.flash')

        @yield('content')
    </main>
</body>
</html>
