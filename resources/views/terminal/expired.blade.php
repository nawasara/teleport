<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Session expired — Nawasara</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #0a0a0a;
            color: #e5e7eb;
            font-family: ui-sans-serif, system-ui, sans-serif;
        }
        .card {
            max-width: 480px;
            padding: 40px;
            text-align: center;
        }
        .icon {
            font-size: 48px;
            margin-bottom: 16px;
        }
        h1 {
            font-size: 22px;
            margin: 0 0 12px;
            color: #fafafa;
        }
        p {
            color: #a3a3a3;
            line-height: 1.6;
            margin: 0 0 24px;
        }
        a {
            display: inline-block;
            padding: 10px 24px;
            background: #10b981;
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: background 0.15s;
        }
        a:hover { background: #059669; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">⏱️</div>
        <h1>Session expired</h1>
        <p>
            Ticket SSH session sudah expired atau sudah dipakai di tab lain.
            Silakan klik Connect lagi dari halaman nodes untuk mendapatkan
            session baru.
        </p>
        <a href="{{ url('nawasara-teleport/nodes') }}">Kembali ke Nodes</a>
    </div>
</body>
</html>
