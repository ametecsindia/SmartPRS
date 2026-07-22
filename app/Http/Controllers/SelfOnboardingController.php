<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Candidate & existing-employee SELF-ONBOARDING portal.
 * Public, token-secured. Progressive save against a Temp-EMP ID; multi-channel
 * OTP; live selfie; document upload; HR verifies & injects into the employees
 * master; on approval the link is disabled but archived (never deleted).
 *
 * Phase 1: token landing + record scaffolding (self-healing tables, matching the
 * OnboardingController convention). OTP, the section wizard, the HR verification
 * console and injection are wired in later phases.
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
