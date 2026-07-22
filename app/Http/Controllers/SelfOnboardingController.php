<?php

namespace App\Http\Controllers;

use App\Services\MailService;
use App\Services\WaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Candidate & existing-employee SELF-ONBOARDING portal.
 * Public, token-secured. Progressive save against a Temp-EMP ID; multi-channel
 * OTP (email + WhatsApp); live selfie; document upload; HR verifies & injects
 * into the employees master; on approval the link is disabled but archived.
 *
 * Phase 1: token landing + self-healing tables.
 * Phase 2: issue()/sendLink() from offer acceptance.
 * Phase 3: OTP, progressive save, selfie, document upload, submit.
 */
class SelfOnboardingController extends Controller
{
    private const SECTIONS = ['personal', 'contact', 'statutory', 'bank'];

    public static function ensureTables(): void
    {
        if (! Schema::hasTable('self_onboarding')) {
            Schema::create('self_onboarding', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->unique();
                $t->string('token', 64)->unique();
                $t->string('temp_emp_code')->index();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('company_id')->nullable()->index();
                $t->unsignedBigInteger('candidate_id')->nullable()->index();
                $t->unsignedBigInteger('employee_id')->nullable()->index();
                $t->string('mode')->default('new');
                $t->string('name')->nullable();
                $t->string('email')->nullable();
                $t->boolean('email_verified')->default(false);
                $t->string('mobile', 20)->nullable();
                $t->boolean('mobile_verified')->default(false);
                $t->string('whatsapp', 20)->nullable();
                $t->boolean('wa_verified')->default(false);
                $t->json('data')->nullable();
                $t->json('flags')->nullable();
                $t->string('selfie_path')->nullable();
                $t->unsignedTinyInteger('progress')->default(0);
                $t->string('status')->default('link_sent');
                $t->string('pin_hash')->nullable();
                $t->timestamp('link_expires_at')->nullable();
                $t->timestamp('link_disabled_at')->nullable();
                $t->timestamp('submitted_at')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('self_onboarding_otps')) {
            Schema::create('self_onboarding_otps', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('self_onboarding_id')->index();
                $t->string('channel', 10);
                $t->string('code_hash');
                $t->unsignedTinyInteger('attempts')->default(0);
                $t->timestamp('expires_at')->nullable();
                $t->timestamp('verified_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('self_onboarding_docs')) {
            Schema::create('self_onboarding_docs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('self_onboarding_id')->index();
                $t->string('kind');
                $t->string('path');
                $t->string('status')->default('pending');
                $t->timestamps();
            });
        }
    }

    public static function issue(array $c): object
    {
        self::ensureTables();

        if (! empty($c['candidate_id'])) {
            $existing = DB::table('self_onboarding')
                ->where('candidate_id', $c['candidate_id'])
                ->when(! empty($c['tenant_id']), fn ($q) => $q->where('tenant_id', $c['tenant_id']))
                ->whereNull('deleted_at')->whereNull('link_disabled_at')
                ->orderByDesc('id')->first();
            if ($existing) {
                return $existing;
            }
        }

        $year = date('Y');
        $prefix = 'TMP-'.$year.'-';
        $max = DB::table('self_onboarding')->where('temp_emp_code', 'like', $prefix.'%')
            ->orderByDesc('temp_emp_code')->value('temp_emp_code');
        $n = $max ? ((int) substr($max, strlen($prefix))) + 1 : 1;
        $code = $prefix.str_pad((string) $n, 5, '0', STR_PAD_LEFT);

        $id = DB::table('self_onboarding')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'token' => bin2hex(random_bytes(24)),
            'temp_emp_code' => $code,
            'tenant_id' => $c['tenant_id'] ?? null,
            'company_id' => $c['company_id'] ?? null,
            'candidate_id' => $c['candidate_id'] ?? null,
            'employee_id' => $c['employee_id'] ?? null,
            'mode' => $c['mode'] ?? 'new',
            'name' => $c['name'] ?? null,
            'email' => $c['email'] ?? null,
            'mobile' => $c['mobile'] ?? null,
            'whatsapp' => $c['whatsapp'] ?? ($c['mobile'] ?? null),
            'status' => 'link_sent',
            'link_expires_at' => now()->addDays(14),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('self_onboarding')->where('id', $id)->first();
    }

    public static function sendLink(object $rec, array $ctx = []): void
    {
        try {
            $link = route('selfonboard.start', $rec->token);
            $first = $rec->name ? explode(' ', trim($rec->name))[0] : '';
            if (! empty($rec->email)) {
                MailService::queue([
                    'tenant_id' => $rec->tenant_id, 'company_id' => $rec->company_id,
                    'to' => $rec->email, 'to_name' => $rec->name,
                    'subject' => 'Complete your onboarding'.(! empty($ctx['brand']) ? ' — '.$ctx['brand'] : ''),
                    'heading' => 'Welcome aboard'.($first ? ', '.$first : '').'!',
                    'intro' => 'Please complete your onboarding — it only takes a few minutes, one simple step at a time.',
                    'lines' => array_filter(['Reference' => $rec->temp_emp_code, 'Position' => $ctx['position'] ?? null]),
                    'cta_label' => 'Start Self-Onboarding', 'cta_url' => $link,
                    'kind' => 'onboarding.selflink', 'sync' => true,
                ]);
            }
        } catch (\Throwable $e) {
        }
    }

    /* ------------------------------------------------------------------ portal */

    public function start(string $token)
    {
        $this->ensureTables();
        $rec = DB::table('self_onboarding')->where('token', $token)->whereNull('deleted_at')->first();

        if (! $rec) {
            return response()->view('self-onboarding.message', ['title' => 'Link not valid',
                'msg' => 'This onboarding link is not recognised. Please contact the HR team that sent it.'], 404);
        }
        if ($rec->link_disabled_at) {
            return response()->view('self-onboarding.message', ['title' => 'Onboarding complete',
                'msg' => 'Your onboarding has been submitted and approved. Thank you — there is nothing more to do here.']);
        }
        if ($rec->link_expires_at && now()->greaterThan($rec->link_expires_at)) {
            return response()->view('self-onboarding.message', ['title' => 'Link expired',
                'msg' => 'This onboarding link has expired. Please ask the HR team to send you a fresh link.']);
        }
        if (($rec->status ?? '') === 'link_sent') {
            DB::table('self_onboarding')->where('id', $rec->id)->update(['status' => 'opened', 'updated_at' => now()]);
        }

        $docs = DB::table('self_onboarding_docs')->where('self_onboarding_id', $rec->id)->pluck('kind')->all();

        return view('self-onboarding.portal', [
            'rec' => $rec,
            'data' => json_decode($rec->data ?: '{}', true) ?: [],
            'docKinds' => $docs,
            'hasSelfie' => (bool) $rec->selfie_path,
        ]);
    }

    /* ------------------------------------------------------------- API (token) */

    private function gate(string $token)
    {
        $this->ensureTables();
        $rec = DB::table('self_onboarding')->where('token', $token)->whereNull('deleted_at')->first();
        if (! $rec) {
            return [null, response()->json(['ok' => false, 'error' => 'This onboarding link is not valid.'], 404)];
        }
        if ($rec->link_disabled_at) {
            return [null, response()->json(['ok' => false, 'error' => 'Your onboarding is already complete.'], 403)];
        }
        if ($rec->link_expires_at && now()->greaterThan($rec->link_expires_at)) {
            return [null, response()->json(['ok' => false, 'error' => 'This link has expired.'], 403)];
        }

        return [$rec, null];
    }

    public function otpSend(Request $r, string $token)
    {
        [$rec, $err] = $this->gate($token);
        if ($err) {
            return $err;
        }
        $channel = $r->input('channel');
        if (! in_array($channel, ['email', 'whatsapp'], true)) {
            return response()->json(['ok' => false, 'error' => 'Unknown channel.'], 422);
        }
        $to = $channel === 'email' ? $rec->email : ($rec->whatsapp ?: $rec->mobile);
        if (! $to) {
            return response()->json(['ok' => false, 'error' => 'No '.$channel.' number/address on file. Please contact HR.'], 422);
        }

        $code = (string) random_int(100000, 999999);
        DB::table('self_onboarding_otps')->insert([
            'self_onboarding_id' => $rec->id, 'channel' => $channel, 'code_hash' => Hash::make($code),
            'attempts' => 0, 'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($channel === 'email') {
            MailService::queue([
                'tenant_id' => $rec->tenant_id, 'company_id' => $rec->company_id, 'to' => $rec->email, 'to_name' => $rec->name,
                'subject' => 'Your SmartPRS verification code', 'heading' => 'Verify your email',
                'intro' => 'Use the one-time code below to verify your email. It is valid for 10 minutes. Do not share it.',
                'lines' => ['Verification code' => $code], 'kind' => 'onboarding.otp', 'sync' => true,
            ]);
        } else {
            WaService::sendTemplate([
                'tenant_id' => $rec->tenant_id, 'mobile' => $to,
                'template' => WaService::templateNameFor('otp', $rec->tenant_id),
                'bodyValues' => [$code], 'kind' => 'onboarding.otp',
            ]);
        }

        if (($rec->status ?? '') === 'opened') {
            DB::table('self_onboarding')->where('id', $rec->id)->update(['status' => 'verifying', 'updated_at' => now()]);
        }

        $out = ['ok' => true, 'sent' => $channel];
        if (app()->environment('local') || config('app.debug')) {
            $out['dev_code'] = $code;   // dev convenience — shown only when APP is local/debug
        }

        return response()->json($out);
    }

    public function otpVerify(Request $r, string $token)
    {
        [$rec, $err] = $this->gate($token);
        if ($err) {
            return $err;
        }
        $channel = $r->input('channel');
        $code = trim((string) $r->input('code'));
        if (! in_array($channel, ['email', 'whatsapp'], true) || $code === '') {
            return response()->json(['ok' => false, 'error' => 'Enter the code.'], 422);
        }
        $row = DB::table('self_onboarding_otps')->where('self_onboarding_id', $rec->id)
            ->where('channel', $channel)->whereNull('verified_at')
            ->where('expires_at', '>', now())->orderByDesc('id')->first();
        if (! $row) {
            return response()->json(['ok' => false, 'error' => 'Code expired — please resend.'], 422);
        }
        if ($row->attempts >= 5) {
            return response()->json(['ok' => false, 'error' => 'Too many attempts — please resend a new code.'], 429);
        }
        if (! Hash::check($code, $row->code_hash)) {
            DB::table('self_onboarding_otps')->where('id', $row->id)->increment('attempts');

            return response()->json(['ok' => false, 'error' => 'Incorrect code. Please try again.'], 422);
        }

        DB::table('self_onboarding_otps')->where('id', $row->id)->update(['verified_at' => now(), 'updated_at' => now()]);
        $upd = ['updated_at' => now()];
        if ($channel === 'email') {
            $upd['email_verified'] = true;
        } else {
            $upd['wa_verified'] = true;
            $upd['mobile_verified'] = true;   // WhatsApp OTP to the mobile verifies the number
        }
        DB::table('self_onboarding')->where('id', $rec->id)->update($upd);
        $rec = DB::table('self_onboarding')->where('id', $rec->id)->first();

        return response()->json(['ok' => true, 'verified' => [
            'email' => (bool) $rec->email_verified,
            'mobile' => (bool) $rec->mobile_verified,
            'whatsapp' => (bool) $rec->wa_verified,
        ]]);
    }

    public function save(Request $r, string $token)
    {
        [$rec, $err] = $this->gate($token);
        if ($err) {
            return $err;
        }
        $section = (string) $r->input('section');
        if (! in_array($section, self::SECTIONS, true)) {
            return response()->json(['ok' => false, 'error' => 'Unknown section.'], 422);
        }
        $all = json_decode($rec->data ?: '{}', true) ?: [];
        $all[$section] = (array) $r->input('data', []);
        $progress = $this->calcProgress($all, $rec);
        DB::table('self_onboarding')->where('id', $rec->id)->update([
            'data' => json_encode($all), 'progress' => $progress,
            'status' => in_array($rec->status, ['submitted', 'verified', 'approved', 'injected'], true) ? $rec->status : 'in_progress',
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'progress' => $progress]);
    }

    public function selfie(Request $r, string $token)
    {
        [$rec, $err] = $this->gate($token);
        if ($err) {
            return $err;
        }
        $img = (string) $r->input('image');
        $bin = base64_decode((string) preg_replace('#^data:image/\w+;base64,#', '', $img), true);
        if (! $bin || strlen($bin) < 500) {
            return response()->json(['ok' => false, 'error' => 'Could not read the photo — please retake.'], 422);
        }
        if (strlen($bin) > 4 * 1024 * 1024) {
            return response()->json(['ok' => false, 'error' => 'Photo too large.'], 422);
        }
        $path = 'self-onboarding/'.$rec->id.'/selfie.jpg';
        Storage::disk('local')->put($path, $bin);
        DB::table('self_onboarding')->where('id', $rec->id)->update(['selfie_path' => $path, 'updated_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function document(Request $r, string $token)
    {
        [$rec, $err] = $this->gate($token);
        if ($err) {
            return $err;
        }
        $kind = (string) $r->input('kind', 'other');
        $file = $r->file('file');
        if (! $file || ! $file->isValid()) {
            return response()->json(['ok' => false, 'error' => 'No file received.'], 422);
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            return response()->json(['ok' => false, 'error' => 'File too large (max 5 MB).'], 422);
        }
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
            return response()->json(['ok' => false, 'error' => 'Only PDF, JPG or PNG allowed.'], 422);
        }
        $path = $file->store('self-onboarding/'.$rec->id, 'local');
        DB::table('self_onboarding_docs')->insert([
            'self_onboarding_id' => $rec->id, 'kind' => $kind, 'path' => $path,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $kinds = DB::table('self_onboarding_docs')->where('self_onboarding_id', $rec->id)->pluck('kind')->all();
        $this->recomputeProgress($rec->id);

        return response()->json(['ok' => true, 'kinds' => $kinds]);
    }

    public function submit(Request $r, string $token)
    {
        [$rec, $err] = $this->gate($token);
        if ($err) {
            return $err;
        }
        $all = json_decode($rec->data ?: '{}', true) ?: [];
        $miss = [];
        if (! $rec->email_verified) {
            $miss[] = 'verify your email';
        }
        if (! $rec->mobile_verified && ! $rec->wa_verified) {
            $miss[] = 'verify your mobile/WhatsApp';
        }
        if (empty($all['personal'])) {
            $miss[] = 'personal details';
        }
        if (empty($all['bank'])) {
            $miss[] = 'bank details';
        }
        if (! $rec->selfie_path) {
            $miss[] = 'take a selfie';
        }
        if ($miss) {
            return response()->json(['ok' => false, 'error' => 'Please complete: '.implode(', ', $miss).'.'], 422);
        }
        DB::table('self_onboarding')->where('id', $rec->id)->update([
            'status' => 'submitted', 'submitted_at' => now(), 'progress' => 100, 'updated_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    /* ------------------------------------------------------------------ helpers */

    private function calcProgress(array $all, $rec): int
    {
        $done = 0;
        $total = 8;
        if (($rec->email_verified ?? false) && (($rec->wa_verified ?? false) || ($rec->mobile_verified ?? false))) {
            $done++;
        }
        foreach (self::SECTIONS as $s) {
            if (! empty($all[$s])) {
                $done++;
            }
        }
        if (! empty($rec->selfie_path)) {
            $done++;
        }
        try {
            if (DB::table('self_onboarding_docs')->where('self_onboarding_id', $rec->id)->exists()) {
                $done++;
            }
        } catch (\Throwable $e) {
        }

        return (int) round($done / $total * 100);
    }

    private function recomputeProgress(int $id): void
    {
        $rec = DB::table('self_onboarding')->where('id', $id)->first();
        if (! $rec) {
            return;
        }
        $all = json_decode($rec->data ?: '{}', true) ?: [];
        DB::table('self_onboarding')->where('id', $id)->update(['progress' => $this->calcProgress($all, $rec), 'updated_at' => now()]);
    }

    /** Serve the stored selfie back (token-secured) — portal preview + HR review. */
    public function selfieImg(string $token)
    {
        $rec = DB::table('self_onboarding')->where('token', $token)->whereNull('deleted_at')->first();
        if (! $rec || ! $rec->selfie_path || ! Storage::disk('local')->exists($rec->selfie_path)) {
            abort(404);
        }

        return response(Storage::disk('local')->get($rec->selfie_path), 200, ['Content-Type' => 'image/jpeg']);
    }
}
