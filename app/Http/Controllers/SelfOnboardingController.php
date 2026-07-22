<?php

namespace App\Http\Controllers;

use App\Services\MailService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Candidate & existing-employee SELF-ONBOARDING portal.
 * Public, token-secured. Progressive save against a Temp-EMP ID; multi-channel
 * OTP; live selfie; document upload; HR verifies & injects into the employees
 * master; on approval the link is disabled but archived (never deleted).
 *
 * Phase 1: token landing + self-healing tables.
 * Phase 2: issue() a Temp-EMP ID + token against a candidate/offer and sendLink().
 */
class SelfOnboardingController extends Controller
{
    /** Create the self-onboarding tables on first use if a migration has not run yet. */
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

    /**
     * Issue (or reuse) a self-onboarding record for a candidate/employee and
     * return it. Generates a Temp-EMP ID (TMP-YYYY-#####) and a secure token.
     * Idempotent: an existing, still-active record for the same candidate is reused.
     *
     * $c keys: tenant_id, company_id, candidate_id?, employee_id?, name, email,
     *          mobile?, whatsapp?, mode? (new|existing|bulk)
     */
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

    /** Email (and, when possible, WhatsApp) the candidate the self-onboarding link. */
    public static function sendLink(object $rec, array $ctx = []): void
    {
        try {
            $link = route('selfonboard.start', $rec->token);
            $first = $rec->name ? explode(' ', trim($rec->name))[0] : '';
            if (! empty($rec->email)) {
                MailService::queue([
                    'tenant_id' => $rec->tenant_id,
                    'company_id' => $rec->company_id,
                    'to' => $rec->email,
                    'to_name' => $rec->name,
                    'subject' => 'Complete your onboarding'.(! empty($ctx['brand']) ? ' — '.$ctx['brand'] : ''),
                    'heading' => 'Welcome aboard'.($first ? ', '.$first : '').'!',
                    'intro' => 'Please complete your onboarding — it only takes a few minutes, one simple step at a time.',
                    'lines' => array_filter([
                        'Reference' => $rec->temp_emp_code,
                        'Position' => $ctx['position'] ?? null,
                    ]),
                    'cta_label' => 'Start Self-Onboarding',
                    'cta_url' => $link,
                    'kind' => 'onboarding.selflink',
                    'sync' => true,
                ]);
            }
        } catch (\Throwable $e) {
            // fail-soft: link is also shown on the offer page
        }
    }

    /** Public landing — validate the token and open the themed portal. */
    public function start(string $token)
    {
        $this->ensureTables();

        $rec = DB::table('self_onboarding')->where('token', $token)->whereNull('deleted_at')->first();

        if (! $rec) {
            return response()->view('self-onboarding.message', [
                'title' => 'Link not valid',
                'msg' => 'This onboarding link is not recognised. Please contact the HR team that sent it.',
            ], 404);
        }
        if ($rec->link_disabled_at) {
            return response()->view('self-onboarding.message', [
                'title' => 'Onboarding complete',
                'msg' => 'Your onboarding has been submitted and approved. There is nothing more to do here — thank you.',
            ]);
        }
        if ($rec->link_expires_at && now()->greaterThan($rec->link_expires_at)) {
            return response()->view('self-onboarding.message', [
                'title' => 'Link expired',
                'msg' => 'This onboarding link has expired. Please ask the HR team to send you a fresh link.',
            ]);
        }

        if (($rec->status ?? '') === 'link_sent') {
            DB::table('self_onboarding')->where('id', $rec->id)->update(['status' => 'opened', 'updated_at' => now()]);
        }

        return view('self-onboarding.portal', ['rec' => $rec]);
    }
}
