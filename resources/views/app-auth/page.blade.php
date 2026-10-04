<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} - {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light dark; --bg: #f4f5f7; --card: #fff; --text: #18181b; --muted: #52525b; --primary: #2563eb; --border: #e4e4e7; }
        @media (prefers-color-scheme: dark) { :root { --bg: #09090b; --card: #18181b; --text: #fafafa; --muted: #a1a1aa; --primary: #3b82f6; --border: #27272a; } }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; box-sizing: border-box; background: var(--bg); color: var(--text); font: 16px/1.5 system-ui, -apple-system, sans-serif; }
        main { width: 100%; max-width: 420px; background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 24px; }
        h1 { font-size: 1.25rem; margin: 0 0 8px; }
        p { margin: 0 0 20px; color: var(--muted); }
        strong { color: var(--text); word-break: break-word; }
        .actions { display: flex; gap: 12px; }
        button { flex: 1; font: inherit; font-weight: 600; padding: 12px; border-radius: 8px; border: 1px solid var(--border); background: transparent; color: var(--text); cursor: pointer; }
        button.primary { background: var(--primary); border-color: var(--primary); color: #fff; }
    </style>
</head>
<body>
<main>
    <h1>{{ $title }}</h1>
    @isset($form)
        <p>Sign the WxNotify app on <strong>{{ $form['device'] }}</strong> in as <strong>{{ $form['email'] }}</strong>?</p>
        <form method="POST" action="{{ route('app-auth.authorize.store') }}">
            @csrf
            @foreach ($form['fields'] as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <div class="actions">
                <button type="submit" name="decision" value="deny">Cancel</button>
                <button type="submit" name="decision" value="allow" class="primary">Allow</button>
            </div>
        </form>
    @else
        <p>{{ $message }}</p>
    @endisset
</main>
</body>
</html>
