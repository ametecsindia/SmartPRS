<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SmartPRS — Sign in</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/css/smartprs.css">
</head>
<body>
<div id="login-page">
    <div class="login-left">
        <div class="login-brand">
            <div class="login-logo"><i class="fas fa-bolt"></i></div>
            <h1>Smart<span>PRS</span></h1>
            <p>HRM · Payroll · Workforce Compliance</p>
        </div>
        <div class="login-features">
            <div class="login-feat">
                <div class="login-feat-icon" style="background:var(--accent-soft);color:var(--accent);"><i class="fas fa-fingerprint"></i></div>
                <div><strong>Biometric Attendance</strong><p>ZKTeco device sync, real-time</p></div>
            </div>
            <div class="login-feat">
                <div class="login-feat-icon" style="background:var(--blue-soft);color:var(--blue);"><i class="fas fa-money-check-dollar"></i></div>
                <div><strong>Automated Payroll</strong><p>Statutory-ready, India compliant</p></div>
            </div>
            <div class="login-feat">
                <div class="login-feat-icon" style="background:var(--green-soft);color:var(--green);"><i class="fas fa-building-user"></i></div>
                <div><strong>Multi-Company</strong><p>SaaS &amp; on-premise, one platform</p></div>
            </div>
        </div>
    </div>

    <div class="login-right">
        <div class="login-form-wrap">
            @if (!empty($superMode))
                <div style="display:inline-flex;align-items:center;gap:7px;background:#1e293b;color:#fff;font-size:12px;font-weight:600;padding:5px 12px;border-radius:20px;margin-bottom:12px;font-family:var(--font2);">
                    <i class="fas fa-shield-halved"></i> Platform Super Admin
                </div>
                <h2>Super Admin sign in</h2>
                <p>Restricted platform access — Super Admins only</p>
            @else
                <h2>Welcome back</h2>
                <p>Sign in to your SmartPRS workspace</p>
            @endif

            @if (session('status'))
                <div style="background:var(--green-soft,#dcfce7);color:#15803d;font-size:13px;padding:11px 14px;border-radius:10px;margin-bottom:18px;font-family:var(--font2);">
                    <i class="fas fa-circle-check"></i> {{ session('status') }}
                </div>
            @endif
            @if ($errors->any())
                <div style="background:var(--red-soft);color:#dc2626;font-size:13px;padding:11px 14px;border-radius:10px;margin-bottom:18px;font-family:var(--font2);">
                    <i class="fas fa-circle-exclamation"></i> {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="/login">
                @csrf
                @if (!empty($superMode))
                    <input type="hidden" name="super" value="1">
                @endif
                <div class="form-group">
                    <label>Email</label>
                    <div class="input-wrap">
                        <i class="fas fa-envelope"></i>
                        <input type="email" name="email" value="{{ old('email') }}" placeholder="you@company.com" required autofocus>
                    </div>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="password" placeholder="••••••••" required>
                    </div>
                </div>
                <div class="form-group" style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                    <span style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="remember" id="remember" style="width:auto;">
                        <label for="remember" style="margin:0;text-transform:none;letter-spacing:0;font-weight:500;">Keep me signed in</label>
                    </span>
                    <a href="/forgot-password" style="font-size:13px;color:var(--accent);font-family:var(--font2);">Forgot password?</a>
                </div>
                <button class="btn-login" type="submit">Sign in <i class="fas fa-arrow-right"></i></button>
            </form>

            <div class="login-footer">
                Demo: <a href="#">admin@smartprs.local</a> · password: <strong style="color:var(--text2);">password</strong>
            </div>
        </div>
    </div>
</div>
</body>
</html>
