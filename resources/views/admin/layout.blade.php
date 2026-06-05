<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>SmartPRS Admin — @yield('title')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root{--navy:#0c1929;--accent:#f97316;--bg:#f0f4f8;--card:#fff;--border:#e2e8f0;--text2:#475569;--text3:#94a3b8;--green:#10b981;--red:#ef4444;}
        *{margin:0;padding:0;box-sizing:border-box;font-family:'Plus Jakarta Sans',sans-serif;}
        body{background:var(--bg);color:var(--navy);}
        header{height:60px;background:var(--navy);color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 24px;}
        .logo{display:flex;align-items:center;gap:10px;font-weight:800;}
        .logo .mark{width:30px;height:30px;border-radius:8px;background:var(--accent);display:flex;align-items:center;justify-content:center;}
        .logo span{color:var(--accent);}
        header nav{display:flex;gap:8px;align-items:center;}
        header a,.lo{color:rgba(255,255,255,.8);font-size:13px;padding:8px 14px;border-radius:8px;border:1px solid rgba(255,255,255,.18);background:transparent;cursor:pointer;}
        header a:hover{background:rgba(255,255,255,.1);color:#fff;}
        header a.active{background:var(--accent);color:#fff;border-color:var(--accent);}
        main{max-width:1000px;margin:28px auto;padding:0 20px;}
        h1{font-size:22px;margin-bottom:4px;} .sub{color:var(--text2);font-size:13px;margin-bottom:22px;font-family:'DM Sans',sans-serif;}
        .card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:22px;margin-bottom:18px;}
        .card h3{font-size:14px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--border);}
        .grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;}
        .grid.c3{grid-template-columns:repeat(3,1fr);}
        .fg{display:flex;flex-direction:column;gap:5px;margin-bottom:6px;}
        .fg.span2{grid-column:span 2;}
        label{font-size:11px;font-weight:600;color:var(--text2);text-transform:uppercase;letter-spacing:.5px;}
        input,select,textarea{padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:14px;background:#f8fafc;outline:none;font-family:'DM Sans',sans-serif;}
        input:focus,select:focus,textarea:focus{border-color:var(--accent);background:#fff;}
        .btn{display:inline-flex;align-items:center;gap:7px;padding:10px 18px;border-radius:9px;font-weight:700;font-size:14px;border:none;cursor:pointer;}
        .btn-primary{background:var(--accent);color:#fff;} .btn-primary:hover{background:#ea6c0f;}
        .btn-outline{background:transparent;border:1px solid var(--border);color:var(--text2);}
        .flash{background:rgba(16,185,129,.12);color:#059669;padding:12px 16px;border-radius:10px;margin-bottom:18px;font-size:14px;font-family:'DM Sans',sans-serif;}
        table{width:100%;border-collapse:collapse;background:var(--card);border-radius:12px;overflow:hidden;}
        th{background:#f8fafc;text-align:left;padding:12px 16px;font-size:11px;text-transform:uppercase;color:var(--text3);border-bottom:1px solid var(--border);}
        td{padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:14px;}
        .pill{padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:rgba(249,115,22,.12);color:var(--accent);}
        .rowsec{border:1px dashed var(--border);border-radius:10px;padding:14px;margin-bottom:12px;}
        @media(max-width:760px){
            .grid,.grid.c3{grid-template-columns:1fr;}
            header{flex-direction:column;height:auto;padding:12px;gap:10px;}
            header nav{flex-wrap:wrap;justify-content:center;}
            main{margin:18px auto;}
        }
    </style>
</head>
<body>
    <header>
        <div class="logo"><span class="mark"><i class="fas fa-bolt"></i></span> Smart<span>PRS</span> Admin</div>
        <nav>
            <a href="{{ route('landing.editor') }}" class="@yield('nav_landing')">Landing CMS</a>
            <a href="{{ route('admin.staff') }}" class="@yield('nav_staff')">Platform Staff</a>
            <a href="{{ route('app') }}">← Back to app</a>
            <form method="POST" action="{{ route('logout') }}" style="display:inline;">@csrf<button class="lo">Sign out</button></form>
        </nav>
    </header>
    <main>
        @if (session('success'))<div class="flash"><i class="fas fa-circle-check"></i> {{ session('success') }}</div>@endif
        @yield('content')
    </main>
</body>
</html>
