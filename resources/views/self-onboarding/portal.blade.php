<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Self-Onboarding — SmartPRS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root{
    --navy:#0c1929;--navy3:#1a3350;--accent:#f97316;--accent-soft:rgba(249,115,22,.1);
    --blue:#3b82f6;--green:#10b981;--amber:#f59e0b;
    --bg:#f0f4f8;--card:#fff;--border:#e2e8f0;--text1:#0f172a;--text2:#475569;--text3:#94a3b8;
  }
  *{box-sizing:border-box}
  body{margin:0;font-family:'Plus Jakarta Sans',system-ui,Segoe UI,sans-serif;background:var(--bg);color:var(--text1)}
  .topbar{background:var(--navy);color:#fff;display:flex;align-items:center;gap:12px;padding:14px 20px}
  .mark{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--accent),#ea580c);
        display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:16px}
  .topbar b{font-weight:800;font-size:15px} .topbar b span{color:var(--accent)}
  .topbar .tag{margin-left:auto;font-size:11px;color:rgba(255,255,255,.5);font-family:'DM Sans',sans-serif;text-transform:uppercase;letter-spacing:1px}
  .wrap{max-width:560px;margin:26px auto;padding:0 16px}
  .card{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;box-shadow:0 12px 40px rgba(12,25,41,.06)}
  .head{padding:22px 24px;border-bottom:1px solid var(--border)}
  .head h1{margin:0 0 4px;font-size:20px}
  .head p{margin:0;color:var(--text2);font-size:13.5px;font-family:'DM Sans',sans-serif}
  .body{padding:20px 24px}
  .meta{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
  .chip{font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px;background:var(--accent-soft);color:var(--accent)}
  .chip.grey{background:#eef2f7;color:var(--text2)}
  .steps{list-style:none;margin:0;padding:0}
  .steps li{display:flex;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid #f1f5f9;font-size:14px}
  .steps li:last-child{border-bottom:none}
  .n{width:24px;height:24px;border-radius:50%;background:#eef2f7;color:var(--text2);font-size:12px;font-weight:700;
     display:flex;align-items:center;justify-content:center;flex-shrink:0}
  .btn{display:block;width:100%;text-align:center;margin-top:20px;padding:14px;border-radius:11px;border:none;
       background:linear-gradient(135deg,var(--accent),#ea580c);color:#fff;font-size:15px;font-weight:700;
       font-family:inherit;box-shadow:0 6px 18px rgba(249,115,22,.3);cursor:pointer}
  .note{margin-top:12px;font-size:12px;color:var(--text3);text-align:center;font-family:'DM Sans',sans-serif}
  .foot{text-align:center;margin:18px 0;font-size:12px;color:var(--text3)}
  .foot b{color:var(--navy)} .foot b span{color:var(--accent)}
</style>
</head>
<body>
  <div class="topbar">
    <div class="mark">S</div>
    <b>Smart<span>PRS</span></b>
    <span class="tag">Self-Onboarding</span>
  </div>
  <div class="wrap">
    <div class="card">
      <div class="head">
        <h1>Welcome{{ $rec->name ? ', '.explode(' ', $rec->name)[0] : '' }} 👋</h1>
        <p>Let’s complete your onboarding. It only takes a few minutes, one simple step at a time.</p>
      </div>
      <div class="body">
        <div class="meta">
          <span class="chip">Ref: {{ $rec->temp_emp_code }}</span>
          <span class="chip grey">Status: {{ ucfirst(str_replace('_',' ', $rec->status)) }}</span>
        </div>
        <ul class="steps">
          <li><span class="n">1</span> Verify your email, mobile &amp; WhatsApp</li>
          <li><span class="n">2</span> Your personal details</li>
          <li><span class="n">3</span> Contact &amp; address</li>
          <li><span class="n">4</span> Statutory IDs (PAN / UAN)</li>
          <li><span class="n">5</span> Bank details</li>
          <li><span class="n">6</span> Take a selfie photo</li>
          <li><span class="n">7</span> Upload documents</li>
          <li><span class="n">8</span> Review &amp; submit</li>
        </ul>
        <button class="btn" disabled>Continue — steps activate shortly</button>
        <div class="note">Secure onboarding · your details are encrypted. Each step is saved as you go.</div>
      </div>
    </div>
    <div class="foot">Powered by <b>Smart<span>PRS</span></b></div>
  </div>
</body>
</html>
