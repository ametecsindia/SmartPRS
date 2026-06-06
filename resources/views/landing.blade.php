<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $c['brand'] }} — {{ $c['hero']['badge'] }}</title>
    <meta name="description" content="{{ $c['hero']['subtitle'] }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" type="image/png" href="{{ asset('images/logo-icon.png') }}">
    <style>
        :root{
            --navy:#0a1628;--navy2:#102744;--accent:#f97316;--accent2:#fb923c;
            --bg:#f6f8fb;--card:#fff;--border:#e8edf3;--text:#0f1d33;--text2:#516074;--text3:#9aa7b8;
            --green:#10b981;--blue:#3b82f6;--purple:#8b5cf6;--ink:#0a1628;
        }
        *{margin:0;padding:0;box-sizing:border-box;font-family:'Plus Jakarta Sans',sans-serif;}
        html{scroll-behavior:smooth;}
        body{color:var(--text);background:var(--card);line-height:1.55;-webkit-font-smoothing:antialiased;overflow-x:hidden;-webkit-text-size-adjust:100%;}
        a{text-decoration:none;color:inherit;}
        img{max-width:100%;height:auto;}
        /* Fluid container (team test #1): percentage-based gutters so every
           desktop width, Android and iPhone gets proportional spacing. */
        .wrap{max-width:1160px;width:100%;margin:0 auto;padding:0 clamp(16px,4vw,24px);}
        .mono2{font-family:'DM Sans',sans-serif;}

        /* ---- buttons ---- */
        .btn{display:inline-flex;align-items:center;gap:9px;padding:13px 24px;border-radius:12px;font-weight:700;font-size:15px;cursor:pointer;border:none;transition:.18s;white-space:nowrap;}
        .btn-accent{background:linear-gradient(135deg,var(--accent),#ea580c);color:#fff;box-shadow:0 10px 28px rgba(249,115,22,.32);}
        .btn-accent:hover{transform:translateY(-2px);box-shadow:0 14px 34px rgba(249,115,22,.42);}
        .btn-light{background:#fff;color:var(--navy);border:1px solid var(--border);}
        .btn-light:hover{border-color:var(--accent);color:var(--accent);}
        .btn-ghost{background:rgba(255,255,255,.08);color:#fff;border:1px solid rgba(255,255,255,.22);}
        .btn-ghost:hover{background:rgba(255,255,255,.16);}

        /* ---- nav ---- */
        nav{position:sticky;top:0;z-index:50;background:rgba(10,22,40,.85);backdrop-filter:blur(14px);border-bottom:1px solid rgba(255,255,255,.06);}
        nav .wrap{display:flex;align-items:center;justify-content:space-between;height:70px;}
        .logo{display:flex;align-items:center;gap:11px;font-weight:800;font-size:19px;color:#fff;}
        .logo .mark{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,var(--accent),#ea580c);display:flex;align-items:center;justify-content:center;box-shadow:0 6px 16px rgba(249,115,22,.4);}
        .logo b{color:var(--accent2);font-weight:800;}
        .navlinks{display:flex;gap:30px;align-items:center;font-size:14.5px;font-weight:500;color:rgba(255,255,255,.72);}
        .navlinks a:hover{color:#fff;}
        .navlinks .btn{padding:9px 18px;font-size:14px;}

        /* ---- hero ---- */
        .hero{background-color:var(--navy);background-size:cover;background-position:right center;background-repeat:no-repeat;color:#fff;padding:112px 0 72px;position:relative;overflow:hidden;min-height:92vh;display:flex;align-items:center;}
        .hero::before{content:'';position:absolute;inset:0;z-index:0;background:linear-gradient(90deg,var(--navy) 0%,rgba(15,29,51,.94) 26%,rgba(15,29,51,.62) 56%,rgba(15,29,51,.12) 100%);}
        .hero .wrap{position:relative;z-index:1;width:100%;}
        .hero-copy{max-width:640px;}
        .badge{display:inline-flex;align-items:center;gap:8px;background:rgba(249,115,22,.14);color:#fdba74;padding:7px 16px;border-radius:30px;font-size:12.5px;font-weight:600;margin-bottom:24px;border:1px solid rgba(249,115,22,.22);}
        .badge .dot{width:7px;height:7px;border-radius:50%;background:var(--accent);box-shadow:0 0 0 4px rgba(249,115,22,.25);}
        .hero h1{font-size:clamp(34px,5.5vw,54px);font-weight:800;letter-spacing:-1.5px;max-width:880px;line-height:1.07;}
        .hero h1 .hl{background:linear-gradient(120deg,var(--accent2),#fcd34d);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;}
        .hero p{color:rgba(255,255,255,.72);font-size:19px;margin:24px 0 36px;max-width:660px;}
        .hero .cta{display:flex;gap:14px;flex-wrap:wrap;align-items:center;}
        .hero .micro{margin-top:18px;font-size:13.5px;color:rgba(255,255,255,.55);display:flex;gap:18px;flex-wrap:wrap;}
        .hero .micro span{display:inline-flex;align-items:center;gap:7px;}
        .hero .micro i{color:var(--green);}
        /* stats */
        .stats{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin-top:70px;}
        .stat{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.09);border-radius:16px;padding:24px;text-align:left;}
        .stat .n{font-size:34px;font-weight:800;background:linear-gradient(120deg,var(--accent2),#fcd34d);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;}
        .stat .l{font-size:13.5px;color:rgba(255,255,255,.62);margin-top:6px;}

        /* ---- logos / trust bar ---- */
        .logobar{background:var(--navy);padding:30px 0;border-top:1px solid rgba(255,255,255,.06);}
        .logobar .wrap{display:flex;align-items:center;justify-content:center;gap:40px;flex-wrap:wrap;}
        .logobar .lbl{color:rgba(255,255,255,.4);font-size:12px;text-transform:uppercase;letter-spacing:1.5px;font-weight:600;}
        .logobar .cli{color:rgba(255,255,255,.55);font-weight:700;font-size:17px;letter-spacing:.3px;}

        /* ---- sections ---- */
        section{padding:92px 0;}
        .sec-head{text-align:center;max-width:680px;margin:0 auto 56px;}
        .eyebrow{color:var(--accent);font-weight:700;font-size:13px;text-transform:uppercase;letter-spacing:1.4px;}
        h2{font-size:38px;font-weight:800;letter-spacing:-1px;margin:12px 0 14px;line-height:1.12;}
        .lead{color:var(--text2);font-size:17px;}
        .alt{background:var(--bg);}

        /* how it works */
        .steps{display:grid;grid-template-columns:repeat(4,1fr);gap:24px;margin-top:20px;counter-reset:step;}
        .step{position:relative;padding:28px 22px;background:var(--card);border:1px solid var(--border);border-radius:18px;}
        .step .num{counter-increment:step;width:42px;height:42px;border-radius:12px;background:rgba(249,115,22,.1);color:var(--accent);font-weight:800;display:flex;align-items:center;justify-content:center;font-size:18px;margin-bottom:16px;}
        .step .num::before{content:'0' counter(step);}
        .step h3{font-size:17px;margin-bottom:8px;}
        .step p{color:var(--text2);font-size:14px;}

        /* features */
        .feat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:22px;}
        .feat{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:26px;transition:.22s;}
        .feat:hover{transform:translateY(-5px);box-shadow:0 22px 50px rgba(15,29,51,.1);border-color:rgba(249,115,22,.3);}
        .feat .ic{width:48px;height:48px;border-radius:13px;background:linear-gradient(135deg,rgba(249,115,22,.14),rgba(249,115,22,.06));color:var(--accent);display:flex;align-items:center;justify-content:center;font-size:20px;margin-bottom:15px;}
        .feat h3{font-size:17px;margin-bottom:8px;}
        .feat p{color:var(--text2);font-size:14px;line-height:1.55;}

        /* security band */
        .secure{background:linear-gradient(160deg,var(--navy),var(--navy2));color:#fff;border-radius:26px;padding:clamp(28px,5vw,54px);display:grid;grid-template-columns:1.1fr 1fr;gap:40px;align-items:center;overflow:hidden;}
        .secure h2{color:#fff;}
        .secure p{color:rgba(255,255,255,.72);font-size:16px;margin-top:8px;}
        .secure .grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
        .secure .item{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:18px;}
        .secure .item i{color:var(--accent2);font-size:20px;}
        .secure .item h4{font-size:14.5px;margin:10px 0 4px;}
        .secure .item p{font-size:12.5px;color:rgba(255,255,255,.6);margin:0;}

        /* pricing */
        .price-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;align-items:start;}
        .plan{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:34px;transition:.2s;}
        .plan:hover{box-shadow:0 18px 44px rgba(15,29,51,.08);}
        .plan.hot{border:2px solid var(--accent);box-shadow:0 24px 60px rgba(249,115,22,.2);position:relative;transform:scale(1.03);}
        .plan.hot::before{content:'★ Most popular';position:absolute;top:-13px;left:50%;transform:translateX(-50%);background:linear-gradient(135deg,var(--accent),#ea580c);color:#fff;font-size:11.5px;font-weight:700;padding:5px 14px;border-radius:20px;white-space:nowrap;}
        .plan h3{font-size:19px;}
        .plan .amt{font-size:42px;font-weight:800;margin:12px 0;letter-spacing:-1px;}
        .plan .amt small{font-size:15px;color:var(--text3);font-weight:500;}
        .plan ul{list-style:none;margin:20px 0 24px;}
        .plan li{padding:8px 0;font-size:14.5px;color:var(--text2);display:flex;gap:10px;align-items:flex-start;}
        .plan li i{color:var(--green);margin-top:3px;font-size:13px;}

        /* testimonials */
        .quotes{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;}
        .quote{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:28px;}
        .quote .stars{color:#f59e0b;font-size:14px;margin-bottom:12px;}
        .quote p{font-size:15px;color:var(--text);font-style:italic;}
        .quote .who{display:flex;align-items:center;gap:12px;margin-top:18px;}
        .quote .av{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;}
        .quote .who b{font-size:14px;display:block;}
        .quote .who span{font-size:12.5px;color:var(--text3);}

        /* faq */
        .faq{max-width:780px;margin:0 auto;}
        .faq-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 30px;max-width:1120px;margin:0 auto;align-items:start;}
        .faq-cat{margin-bottom:10px;}
        .faq-cat h3{font-size:13px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:var(--text1);margin:6px 0 12px;display:flex;align-items:center;gap:9px;}
        .faq-cat h3 .dot{width:9px;height:9px;border-radius:50%;background:var(--accent);flex:0 0 auto;}
        details{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:0 20px;margin-bottom:12px;transition:.2s;}
        details[open]{border-color:rgba(249,115,22,.35);box-shadow:0 10px 30px rgba(15,29,51,.06);}
        summary{list-style:none;cursor:pointer;padding:20px 0;font-weight:700;font-size:16px;display:flex;justify-content:space-between;align-items:center;gap:16px;}
        summary::-webkit-details-marker{display:none;}
        summary i{color:var(--accent);transition:.2s;flex-shrink:0;}
        details[open] summary i{transform:rotate(45deg);}
        details p{color:var(--text2);font-size:14.5px;padding:0 0 20px;margin:0;}

        /* CTA band */
        .band{background:linear-gradient(135deg,var(--accent),#ea580c);color:#fff;border-radius:28px;padding:60px 48px;text-align:center;position:relative;overflow:hidden;}
        .band::after{content:'';position:absolute;right:-80px;bottom:-80px;width:300px;height:300px;border-radius:50%;background:rgba(255,255,255,.08);}
        .band h2{color:#fff;font-size:clamp(26px,4vw,36px);position:relative;}
        .band p{color:rgba(255,255,255,.92);margin:14px 0 30px;font-size:17px;position:relative;}
        .band .row{display:flex;gap:14px;justify-content:center;flex-wrap:wrap;position:relative;}
        .band .meta{margin-top:22px;font-size:14px;opacity:.92;position:relative;}

        /* footer */
        footer{background:var(--navy);color:rgba(255,255,255,.6);padding:64px 0 32px;font-size:14px;}
        footer .cols{display:grid;grid-template-columns:1.6fr 1fr 1fr 1fr;gap:32px;margin-bottom:40px;}
        footer h5{color:#fff;font-size:13px;text-transform:uppercase;letter-spacing:1px;margin-bottom:16px;}
        footer .lk{display:block;padding:6px 0;color:rgba(255,255,255,.6);}
        footer .lk:hover{color:#fff;}
        footer .blurb{color:rgba(255,255,255,.5);font-size:14px;margin-top:14px;max-width:280px;}
        footer .bottom{border-top:1px solid rgba(255,255,255,.1);padding-top:24px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:12px;font-size:13px;}

        @media(max-width:920px){
            .stats,.feat-grid,.price-grid,.steps,.quotes{grid-template-columns:1fr 1fr;}
            .secure{grid-template-columns:1fr;}
            footer .cols{grid-template-columns:1fr 1fr;}
            .hero{min-height:auto;padding:96px 0 60px;background-position:72% center;}
            .hero-copy{max-width:none;}
            .hero::before{background:linear-gradient(180deg,rgba(15,29,51,.9),rgba(15,29,51,.82));}
        }
        @media(max-width:620px){
            .navlinks a:not(.btn){display:none;}
            .hero h1{font-size:36px;letter-spacing:-1px;}
            .hero p{font-size:17px;}
            h2{font-size:29px;}
            .stats,.feat-grid,.price-grid,.steps,.quotes,.secure .grid,footer .cols,.faq-grid{grid-template-columns:1fr;}
            .plan.hot{transform:none;}
            section{padding:64px 0;}
            .band,.secure{padding:36px 24px;}
        }
    </style>
</head>
<body>
    <nav>
        <div class="wrap">
            <a href="/" class="logo"><img src="{{ asset('images/logo.png') }}" alt="SmartPRS — Reputation | Relationships | Results" style="height:40px;width:auto;display:block;"></a>
            <div class="navlinks">
                <a href="#features">Features</a>
                <a href="#how">How it works</a>
                <a href="#pricing">Pricing</a>
                <a href="#faq">FAQ</a>
                <a href="{{ route('login') }}" class="btn btn-ghost">Sign in</a>
                <a href="#contact" class="btn btn-accent">{{ $c['hero']['cta'] }}</a>
            </div>
        </div>
    </nav>

    <header class="hero" style="background-image:url('{{ asset($c['hero']['image'] ?? 'images/hero.png') }}')">
        <div class="wrap">
            <div class="hero-copy">
                <span class="badge"><span class="dot"></span> {{ $c['hero']['badge'] }}</span>
                <h1>{{ $c['hero']['title'] }}</h1>
                <p>{{ $c['hero']['subtitle'] }}</p>
                <div class="cta">
                    <a href="#contact" class="btn btn-accent">{{ $c['hero']['cta'] }} <i class="fas fa-arrow-right"></i></a>
                    <a href="{{ route('login') }}" class="btn btn-ghost"><i class="fas fa-right-to-bracket"></i> {{ $c['hero']['cta2'] }}</a>
                </div>
                <div class="micro">
                    <span><i class="fas fa-check-circle"></i> No card required for a demo</span>
                    <span><i class="fas fa-check-circle"></i> SaaS or on-premise</span>
                    <span><i class="fas fa-check-circle"></i> India statutory-ready</span>
                </div>
                <div class="stats">
                    @foreach ($c['stats'] as $s)
                        <div class="stat"><div class="n">{{ $s['n'] }}</div><div class="l">{{ $s['l'] }}</div></div>
                    @endforeach
                </div>
            </div>
        </div>
    </header>

    <div class="logobar">
        <div class="wrap">
            <span class="lbl">Trusted by collections &amp; recovery teams</span>
            @foreach ($c['clients'] as $cl)
                <span class="cli">{{ $cl['name'] }}</span>
            @endforeach
        </div>
    </div>

    <section id="features">
        <div class="wrap">
            <div class="sec-head">
                <div class="eyebrow">Platform · 16 modules</div>
                <h2>Sixteen modules. One workforce platform.</h2>
                <p class="lead">Every HR function your collections &amp; recovery business runs on — from hiring and biometric attendance to statutory payroll, field-force compliance and analytics — built in.</p>
            </div>
            <div class="feat-grid">
                @foreach ($c['features'] as $f)
                    <div class="feat">
                        <div class="ic"><i class="fas fa-{{ $f['icon'] }}"></i></div>
                        <h3>{{ $f['title'] }}</h3>
                        <p>{{ $f['desc'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="alt" id="how">
        <div class="wrap">
            <div class="sec-head">
                <div class="eyebrow">How it works</div>
                <h2>Live in days, not months</h2>
                <p class="lead">A guided onboarding gets your companies, people and payroll running fast.</p>
            </div>
            <div class="steps">
                <div class="step"><div class="num"></div><h3>Onboard your company</h3><p>We set up your tenant, branding and the first company in minutes.</p></div>
                <div class="step"><div class="num"></div><h3>Add people &amp; devices</h3><p>Import employees, link biometric devices and configure pay structures.</p></div>
                <div class="step"><div class="num"></div><h3>Run compliant payroll</h3><p>Attendance flows into PF/ESI/PT/TDS-ready payroll with approvals.</p></div>
                <div class="step"><div class="num"></div><h3>Pay &amp; stay compliant</h3><p>Generate bank files, payslips and track DRA/PCC compliance automatically.</p></div>
            </div>
        </div>
    </section>

    <section id="security">
        <div class="wrap">
            <div class="secure">
                <div>
                    <div class="eyebrow">Security &amp; compliance</div>
                    <h2>Built for India, secure by design</h2>
                    <p>Role-based access, full audit trails and statutory formats — so payroll and field-force data stay accurate and protected.</p>
                </div>
                <div class="grid">
                    <div class="item"><i class="fas fa-user-shield"></i><h4>Role-based access</h4><p>Granular permissions per role and module.</p></div>
                    <div class="item"><i class="fas fa-file-invoice-dollar"></i><h4>Statutory formats</h4><p>PF ECR, ESI, PT and TDS-ready outputs.</p></div>
                    <div class="item"><i class="fas fa-clipboard-check"></i><h4>Audit trails</h4><p>Every approval and change is logged.</p></div>
                    <div class="item"><i class="fas fa-database"></i><h4>Your data, your choice</h4><p>Cloud SaaS or on-premise deployment.</p></div>
                </div>
            </div>
        </div>
    </section>

    <section class="alt" id="pricing">
        <div class="wrap">
            <div class="sec-head">
                <div class="eyebrow">Pricing</div>
                <h2>Simple, scalable plans</h2>
                <p class="lead">All 16 modules in every plan — pricing changes only with your team size. Pay 12 months in advance and save 25%.</p>
            </div>
            <div class="price-grid">
                @foreach ($c['plans'] as $p)
                    <div class="plan {{ ($p['highlight'] ?? '0') == '1' ? 'hot' : '' }}">
                        <h3>{{ $p['name'] }}</h3>
                        <div class="amt">{{ $p['price'] }}<small>{{ $p['period'] }}</small></div>
                        <ul>
                            @foreach (array_filter(array_map('trim', explode(',', $p['features'] ?? ''))) as $li)
                                <li><i class="fas fa-check"></i> {{ $li }}</li>
                            @endforeach
                        </ul>
                        <a href="{{ url('/signup') }}?plan={{ urlencode($p['name']) }}" class="btn {{ ($p['highlight'] ?? '0') == '1' ? 'btn-accent' : 'btn-light' }}" style="width:100%;justify-content:center;">Get started</a>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section id="testimonials">
        <div class="wrap">
            <div class="sec-head">
                <div class="eyebrow">Customer stories</div>
                <h2>Teams already running on SmartPRS</h2>
            </div>
            <div class="quotes">
                <div class="quote">
                    <div class="stars">★★★★★</div>
                    <p>"Payroll that used to take us three days now closes in an afternoon — and the statutory files are ready to file."</p>
                    <div class="who"><div class="av">RS</div><div><b>Operations Head</b><span>Exon Recovery Services</span></div></div>
                </div>
                <div class="quote">
                    <div class="stars">★★★★★</div>
                    <p>"DRA and PCC expiries used to slip through. Now we get alerts before they lapse — a huge compliance relief."</p>
                    <div class="who"><div class="av">NU</div><div><b>Compliance Manager</b><span>Numero Uno Financial</span></div></div>
                </div>
                <div class="quote">
                    <div class="stars">★★★★★</div>
                    <p>"Running all our group companies from one platform, with one login, changed how we operate."</p>
                    <div class="who"><div class="av">SC</div><div><b>Managing Director</b><span>Storm Collections</span></div></div>
                </div>
            </div>
        </div>
    </section>

    <section class="alt" id="faq">
        <div class="wrap">
            <div class="sec-head">
                <div class="eyebrow">FAQ</div>
                <h2>Questions, answered</h2>
            </div>
            <div class="faq-grid">
                <div class="faq-cat">
                    <h3><span class="dot"></span> Platform &amp; modules</h3>
                    <details open><summary>What does SmartPRS cover? <i class="fas fa-plus"></i></summary><p>Sixteen modules across hiring, people, attendance, leave, payroll, compensation, statutory compliance, performance, learning, HR letters, field force, communication, reports, administration and the SaaS platform — in one system.</p></details>
                    <details><summary>Can it run multiple companies? <i class="fas fa-plus"></i></summary><p>Yes. A single tenant manages many companies, with a group view plus per-company data, branding and payroll.</p></details>
                    <details><summary>Is it built specifically for collections &amp; recovery? <i class="fas fa-plus"></i></summary><p>Yes — on-roll and off-roll agents, field operations and RBI / DRA / PCC obligations are first-class, not bolted on.</p></details>
                    <details><summary>Can modules be limited per plan or company? <i class="fas fa-plus"></i></summary><p>Yes. Module access can be gated by subscription plan, and within that every screen follows each user's role.</p></details>
                </div>
                <div class="faq-cat">
                    <h3><span class="dot"></span> Payroll &amp; compliance</h3>
                    <details><summary>Is it compliant with Indian payroll statutes? <i class="fas fa-plus"></i></summary><p>Yes: PF, ESI, Professional Tax and TDS, with statutory-ready outputs including PF ECR and ESI challans, configurable to your rates.</p></details>
                    <details><summary>How is field-force compliance handled? <i class="fas fa-plus"></i></summary><p>We track DRA certification, police verification (PCC) and agent authorisations, with alerts before anything expires.</p></details>
                    <details><summary>How are incentives &amp; commissions managed? <i class="fas fa-plus"></i></summary><p>A built-in engine computes payouts on a collected, target or manual basis (flat, slab or portfolio formulas) and folds them into payroll.</p></details>
                    <details><summary>Is sensitive data protected? <i class="fas fa-plus"></i></summary><p>Role-based access, full audit trails and DPDP-2023-aligned handling of employee and borrower data.</p></details>
                </div>
                <div class="faq-cat">
                    <h3><span class="dot"></span> Attendance &amp; field force</h3>
                    <details><summary>Does it support biometric devices? <i class="fas fa-plus"></i></summary><p>Yes — ZKTeco device sync plus geo-fenced in-app punch for field agents, all flowing into one payroll.</p></details>
                    <details><summary>How does it stop attendance fraud? <i class="fas fa-plus"></i></summary><p>Geo-fenced selfie / GPS punch ties every attendance mark to a real location and time.</p></details>
                    <details><summary>Can it manage off-roll / commission agents? <i class="fas fa-plus"></i></summary><p>Yes — engage vendor / off-roll agents with full KYC (photo, ID, PAN, DRA, PCC, bank), kept separate from payroll employees.</p></details>
                    <details><summary>What is the escalation desk? <i class="fas fa-plus"></i></summary><p>A tracker for bank escalations with penalties and resolution SLAs, managed end to end.</p></details>
                </div>
                <div class="faq-cat">
                    <h3><span class="dot"></span> Deployment, security &amp; pricing</h3>
                    <details><summary>SaaS or on-premise? <i class="fas fa-plus"></i></summary><p>Both — multi-company SaaS, or an on-premise install with a perpetual licence so your data stays on your infrastructure.</p></details>
                    <details><summary>How long does onboarding take? <i class="fas fa-plus"></i></summary><p>Most teams are live within days. We set up your tenant, import people and configure pay structures with you.</p></details>
                    <details><summary>How does pricing work? <i class="fas fa-plus"></i></summary><p>Every plan includes all 16 modules — the price depends only on your team size. Starter covers 25 employees, Growth 75, Professional 150, with a small per-employee rate beyond that. Billing is minimum 3 months in advance; pay 6 months for 10% off or 12 months for 25% off. GST tax invoices are emailed automatically.</p></details>
                    <details><summary>Do you support GST invoicing &amp; auto-renewal? <i class="fas fa-plus"></i></summary><p>Yes — GST tax invoices are generated and emailed, with automated subscription renewals.</p></details>
                </div>
            </div>
        </div>
    </section>

    {{-- rev 89 (Ejaz): lead-generation demo form, modelled on smartdcm.app.
         WhatsApp number + alert recipients are edited in /admin/landing (CMS). --}}
    <section id="contact" style="background:#f1f5f9;">
        <div class="wrap">
            <div class="sec-head">
                <div class="eyebrow">Book a live demo</div>
                <h2 style="color:var(--navy);">See SmartPRS in action for your companies</h2>
                <p class="lead" style="color:#475569;">Share a few details and our team will schedule a personalised walkthrough for your collections &amp; recovery business — SaaS or on-premise.</p>
            </div>
            <div style="display:grid;grid-template-columns:1.25fr 1fr;gap:26px;align-items:start;" class="lead-grid">
                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:26px 28px;box-shadow:0 10px 28px rgba(15,29,51,.07);">
                    <form id="leadForm" onsubmit="return spLeadSubmit(event)">
                        <input type="text" name="website" value="" style="display:none" tabindex="-1" autocomplete="off">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;" class="lead-fields">
                            <div class="lf"><label>Full Name*</label><input name="name" required maxlength="120"></div>
                            <div class="lf"><label>Company Name*</label><input name="company" required maxlength="160"></div>
                            <div class="lf"><label>Designation</label><input name="designation" maxlength="120"></div>
                            <div class="lf"><label>City / Location*</label><input name="city" required maxlength="120"></div>
                            <div class="lf"><label>Mobile Number*</label><input name="mobile" required maxlength="20" inputmode="tel"></div>
                            <div class="lf"><label>Official Email ID*</label><input name="email" type="email" required maxlength="160"></div>
                            <div class="lf" style="grid-column:1/-1;"><label>Number of Employees</label>
                                <select name="employees">
                                    <option value="">Select</option>
                                    <option>1 - 25</option>
                                    <option>26 - 75</option>
                                    <option>76 - 150</option>
                                    <option>More than 150</option>
                                </select>
                            </div>
                            <div class="lf" style="grid-column:1/-1;"><label>What challenges are you facing today?</label>
                                <textarea name="challenges" rows="3" maxlength="2000" placeholder="Example: payroll taking days, attendance fraud, DRA/PCC expiries slipping, incentive disputes, multiple companies on spreadsheets…"></textarea>
                            </div>
                        </div>
                        <p style="font-size:12px;color:#64748b;margin:12px 0 16px;">By submitting this form, you agree to be contacted by Ametecs India for a SmartPRS demo and related communication.</p>
                        <div style="display:flex;gap:12px;flex-wrap:wrap;">
                            <button type="submit" class="btn btn-accent" id="leadBtn"><i class="fas fa-calendar-check"></i> Schedule My Demo</button>
                            <a href="https://wa.me/{{ preg_replace('/\D+/', '', $c['contact']['whatsapp'] ?? '') }}?text={{ urlencode('Hi! I would like a SmartPRS demo for my company.') }}" onclick="return spLeadWa(this)" target="_blank" rel="noopener" class="btn" style="background:#25d366;color:#fff;justify-content:center;"><i class="fab fa-whatsapp"></i> Chat on WhatsApp</a>
                        </div>
                        <div id="leadMsg" style="display:none;margin-top:14px;padding:12px 14px;border-radius:10px;font-size:14px;"></div>
                    </form>
                </div>
                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:26px 28px;">
                    <h3 style="margin:0 0 14px;color:var(--navy);font-size:19px;">What happens after you submit?</h3>
                    <ol style="margin:0;padding-left:20px;color:#334155;font-size:14.5px;line-height:1.7;">
                        <li>Our team validates your requirement and team size.</li>
                        <li>We schedule a live SmartPRS demo over Zoom or Google Meet.</li>
                        <li>We map your companies, attendance and payroll flow into SmartPRS and show sample payslips and reports.</li>
                        <li>Commercials and deployment options (SaaS or on-premise) are shared after the demo.</li>
                    </ol>
                    <p style="font-size:13px;color:#64748b;margin:14px 0 0;">No obligations. No lock-in. The goal of the demo is to show how SmartPRS fits your collections &amp; recovery operation.</p>
                    <hr style="border:none;border-top:1px solid #e2e8f0;margin:16px 0;">
                    <div style="font-size:14px;color:#334155;line-height:1.9;">
                        <div><i class="fas fa-envelope" style="color:var(--accent);width:20px;"></i> <a href="mailto:{{ $c['contact']['email'] }}" style="color:#334155;">{{ $c['contact']['email'] }}</a></div>
                        <div><i class="fas fa-phone" style="color:var(--accent);width:20px;"></i> {{ $c['contact']['phone'] }}</div>
                        <div><i class="fas fa-location-dot" style="color:var(--accent);width:20px;"></i> {{ $c['contact']['address'] }}</div>
                    </div>
                </div>
            </div>
        </div>
        <style>
            .lf label{display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:5px;}
            .lf input,.lf select,.lf textarea{width:100%;padding:10px 12px;border:1.5px solid #cbd5e1;border-radius:9px;font-size:14px;font-family:inherit;background:#f8fafc;}
            .lf input:focus,.lf select:focus,.lf textarea:focus{outline:none;border-color:var(--accent);background:#fff;}
            @media (max-width:920px){.lead-grid{grid-template-columns:1fr !important;}.lead-fields{grid-template-columns:1fr !important;}}
        </style>
        <script>
            // rev 91b (Ejaz): the WhatsApp button must CARRY the filled form details
            // into the chat, not just a fixed greeting. It also quietly saves the
            // lead to the database when the required fields are complete.
            function spLeadWa(a) {
                try {
                    var f = document.getElementById('leadForm');
                    var d = {};
                    new FormData(f).forEach(function (v, k) { d[k] = String(v || '').trim(); });
                    var lines = ['Hi! I would like a SmartPRS demo for my company.'];
                    if (d.name) lines.push('Name: ' + d.name + (d.designation ? ' (' + d.designation + ')' : ''));
                    if (d.company) lines.push('Company: ' + d.company);
                    if (d.city) lines.push('City: ' + d.city);
                    if (d.mobile) lines.push('Mobile: ' + d.mobile);
                    if (d.email) lines.push('Email: ' + d.email);
                    if (d.employees) lines.push('Employees: ' + d.employees);
                    if (d.challenges) lines.push('Challenges: ' + d.challenges);
                    var base = a.href.split('?')[0];
                    var url = base + '?text=' + encodeURIComponent(lines.join('\n'));
                    // Best-effort: also record the lead (once) when the form is complete.
                    if (!window.__leadSaved && d.name && d.company && d.city && d.mobile && d.email) {
                        window.__leadSaved = true;
                        fetch('{{ route('lead.store') }}', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                            body: JSON.stringify(d)
                        }).catch(function () {});
                    }
                    window.open(url, '_blank', 'noopener');
                    return false;   // we opened it ourselves with the composed text
                } catch (e) {
                    return true;    // fall back to the plain href
                }
            }
            function spLeadSubmit(ev) {
                ev.preventDefault();
                var f = document.getElementById('leadForm');
                var btn = document.getElementById('leadBtn');
                var msg = document.getElementById('leadMsg');
                var data = {};
                new FormData(f).forEach(function (v, k) { data[k] = v; });
                btn.disabled = true; btn.style.opacity = '.6';
                var ok9 = 'background:#dcfce7;color:#166534;border:1px solid #bbf7d0;';
                var bad9 = 'background:#fee2e2;color:#991b1b;border:1px solid #fecaca;';
                var base9 = 'display:block;margin-top:14px;padding:12px 14px;border-radius:10px;font-size:14px;';
                fetch('{{ route('lead.store') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: JSON.stringify(data)
                }).then(function (r) { return r.json().then(function (j) { return { s: r.status, j: j }; }); })
                .then(function (res) {
                    btn.disabled = false; btn.style.opacity = '1';
                    if (res.s === 200 && res.j && res.j.ok) {
                        window.__leadSaved = true;   // a later WhatsApp click must not save a duplicate
                        msg.style.cssText = base9 + ok9;
                        msg.innerHTML = '<i class="fas fa-circle-check"></i> ' + (res.j.message || 'Thank you! Our team will contact you shortly.');
                        f.reset();
                    } else {
                        var errs = res.j && res.j.errors ? Object.values(res.j.errors).map(function (a) { return a[0]; }).join(' ') : 'Please check the required fields and try again.';
                        msg.style.cssText = base9 + bad9;
                        msg.innerHTML = '<i class="fas fa-circle-exclamation"></i> ' + errs;
                    }
                }).catch(function () {
                    btn.disabled = false; btn.style.opacity = '1';
                    msg.style.cssText = base9 + bad9;
                    msg.innerHTML = 'Could not submit right now — please use the WhatsApp button instead.';
                });
                return false;
            }
        </script>
    </section>

    <footer>
        <div class="wrap">
            <div class="cols">
                <div>
                    <div class="logo"><img src="{{ asset('images/logo.png') }}" alt="SmartPRS — Reputation | Relationships | Results" style="height:42px;width:auto;display:block;"></div>
                    <p class="blurb">{{ $c['brand'] }} {{ $c['tagline'] }} — the complete workforce platform for India's collections &amp; recovery industry.</p>
                </div>
                <div>
                    <h5>Product</h5>
                    <a class="lk" href="#features">Features</a>
                    <a class="lk" href="#how">How it works</a>
                    <a class="lk" href="#pricing">Pricing</a>
                    <a class="lk" href="#security">Security</a>
                </div>
                <div>
                    <h5>Company</h5>
                    <a class="lk" href="#testimonials">Customers</a>
                    <a class="lk" href="#faq">FAQ</a>
                    <a class="lk" href="#contact">Contact</a>
                    <a class="lk" href="{{ route('login') }}">Sign in</a>
                </div>
                <div>
                    <h5>Get in touch</h5>
                    <a class="lk" href="mailto:{{ $c['contact']['email'] }}">{{ $c['contact']['email'] }}</a>
                    <span class="lk">{{ $c['contact']['phone'] }}</span>
                    <span class="lk">{{ $c['contact']['address'] }}</span>
                </div>
            </div>
            <div class="bottom">
                <span>{{ $c['footer'] }}</span>
                <span>{{ $c['brand'] }} {{ $c['tagline'] }}</span>
            </div>
        </div>
    </footer>
</body>
</html>
