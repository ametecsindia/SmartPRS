@extends('admin.layout')
@section('title', 'Landing CMS')
@section('nav_landing', 'active')

@section('content')
    <h1>Landing Page</h1>
    <p class="sub">Edit your public website at the site root. Changes go live immediately. <a href="/" target="_blank" style="color:var(--accent)">Open site ↗</a></p>

    <form method="POST" action="{{ route('landing.save') }}">
        @csrf

        <div class="card">
            <h3><i class="fas fa-flag" style="color:var(--accent)"></i> Brand & Hero</h3>
            <div class="grid">
                <div class="fg"><label>Brand</label><input name="content[brand]" value="{{ $c['brand'] }}"></div>
                <div class="fg"><label>Tagline</label><input name="content[tagline]" value="{{ $c['tagline'] }}"></div>
                <div class="fg span2"><label>Hero badge</label><input name="content[hero][badge]" value="{{ $c['hero']['badge'] }}"></div>
                <div class="fg span2"><label>Hero title</label><input name="content[hero][title]" value="{{ $c['hero']['title'] }}"></div>
                <div class="fg span2"><label>Hero subtitle</label><textarea name="content[hero][subtitle]" rows="2">{{ $c['hero']['subtitle'] }}</textarea></div>
                <div class="fg"><label>Primary button</label><input name="content[hero][cta]" value="{{ $c['hero']['cta'] }}"></div>
                <div class="fg"><label>Secondary button</label><input name="content[hero][cta2]" value="{{ $c['hero']['cta2'] }}"></div>
            </div>
        </div>

        <div class="card">
            <h3><i class="fas fa-chart-simple" style="color:var(--accent)"></i> Stats (hero strip)</h3>
            @foreach (array_pad($c['stats'], 4, ['n'=>'','l'=>'']) as $i => $s)
                <div class="grid"><div class="fg"><label>Number</label><input name="content[stats][{{ $i }}][n]" value="{{ $s['n'] ?? '' }}"></div><div class="fg"><label>Label</label><input name="content[stats][{{ $i }}][l]" value="{{ $s['l'] ?? '' }}"></div></div>
            @endforeach
        </div>

        <div class="card">
            <h3><i class="fas fa-grip" style="color:var(--accent)"></i> Features</h3>
            @foreach (array_pad($c['features'], 6, ['icon'=>'','title'=>'','desc'=>'']) as $i => $f)
                <div class="rowsec"><div class="grid c3">
                    <div class="fg"><label>Icon (Font Awesome name)</label><input name="content[features][{{ $i }}][icon]" value="{{ $f['icon'] ?? '' }}" placeholder="fingerprint"></div>
                    <div class="fg"><label>Title</label><input name="content[features][{{ $i }}][title]" value="{{ $f['title'] ?? '' }}"></div>
                    <div class="fg"><label>Description</label><input name="content[features][{{ $i }}][desc]" value="{{ $f['desc'] ?? '' }}"></div>
                </div></div>
            @endforeach
        </div>

        <div class="card">
            <h3><i class="fas fa-tags" style="color:var(--accent)"></i> Pricing plans</h3>
            @foreach (array_pad($c['plans'], 3, ['name'=>'','price'=>'','period'=>'','highlight'=>'0','features'=>'']) as $i => $p)
                <div class="rowsec"><div class="grid c3">
                    <div class="fg"><label>Plan name</label><input name="content[plans][{{ $i }}][name]" value="{{ $p['name'] ?? '' }}"></div>
                    <div class="fg"><label>Price</label><input name="content[plans][{{ $i }}][price]" value="{{ $p['price'] ?? '' }}"></div>
                    <div class="fg"><label>Period</label><input name="content[plans][{{ $i }}][period]" value="{{ $p['period'] ?? '' }}" placeholder="/mo"></div>
                    <div class="fg"><label>Highlight (1 = popular)</label><input name="content[plans][{{ $i }}][highlight]" value="{{ $p['highlight'] ?? '0' }}"></div>
                    <div class="fg span2"><label>Features (comma-separated)</label><input name="content[plans][{{ $i }}][features]" value="{{ $p['features'] ?? '' }}"></div>
                </div></div>
            @endforeach
        </div>

        <div class="card">
            <h3><i class="fas fa-handshake" style="color:var(--accent)"></i> Clients & Contact</h3>
            <div class="grid c3">
                @foreach (array_pad($c['clients'], 4, ['name'=>'']) as $i => $cl)
                    <div class="fg"><label>Client {{ $i+1 }}</label><input name="content[clients][{{ $i }}][name]" value="{{ $cl['name'] ?? '' }}"></div>
                @endforeach
            </div>
            <div class="grid c3" style="margin-top:10px;">
                <div class="fg"><label>Contact email</label><input name="content[contact][email]" value="{{ $c['contact']['email'] }}"></div>
                <div class="fg"><label>Phone</label><input name="content[contact][phone]" value="{{ $c['contact']['phone'] }}"></div>
                <div class="fg"><label>Address</label><input name="content[contact][address]" value="{{ $c['contact']['address'] }}"></div>
            </div>
            <div class="fg" style="margin-top:10px;"><label>Footer text</label><input name="content[footer]" value="{{ $c['footer'] }}"></div>
        </div>

        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <a href="/" target="_blank" class="btn btn-outline">Preview site</a>
            <button class="btn btn-primary"><i class="fas fa-check"></i> Save landing page</button>
        </div>
    </form>
@endsection
