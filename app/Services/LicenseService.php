<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rev 107 — LICENSING CORE (SRS FR-6..FR-8, approved defaults).
 *
 * Platform side: key generation, lookup, activation, AMC checks, events.
 * Keys: SPRS-XXXX-XXXX-XXXX-XXXX (unambiguous charset). Stored BOTH as a
 * sha256 hash (fast, leak-safe lookup) and Crypt-encrypted (so the panel can
 * re-show a key to the installing engineer — same pattern as gateway secrets).
 *
 * Tables are self-created with Schema guards (house convention, no migrations).
 */
class LicenseService
{
    private const CHARSET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public static function ensureTables(): void
    {
        try {
            if (! Schema::hasTable('onprem_clients')) {
                Schema::create('onprem_clients', function ($t) {
                    $t->id();
                    $t->string('company');
                    $t->string('contact_name')->nullable();
                    $t->string('email')->nullable();
                    $t->string('mobile', 30)->nullable();
                    $t->string('gstin', 20)->nullable();
                    $t->string('state', 60)->nullable();
                    $t->text('address')->nullable();
                    $t->string('edition', 8)->default('l1');           // l1|l2|l3
                    $t->string('employee_band', 30)->nullable();        // e.g. "up to 250"
                    $t->decimal('price', 12, 2)->default(0);            // one-time licence price
                    $t->decimal('amc_percent', 5, 2)->default(18);      // Q1 default 18%
                    $t->decimal('paid_total', 12, 2)->default(0);
                    $t->boolean('activate_on_partial')->default(false); // Q2: super-admin tick
                    $t->text('notes')->nullable();
                    $t->timestamps();
                });
            }
            if (! Schema::hasTable('onprem_payments')) {
                Schema::create('onprem_payments', function ($t) {
                    $t->id();
                    $t->unsignedBigInteger('client_id')->index();
                    $t->decimal('amount', 12, 2);
                    $t->string('mode', 30)->default('neft');            // neft|cheque|upi|gateway|cash
                    $t->string('reference')->nullable();
                    $t->date('paid_on')->nullable();
                    $t->string('entered_by')->nullable();
                    $t->timestamps();
                });
            }
            if (! Schema::hasTable('licences')) {
                Schema::create('licences', function ($t) {
                    $t->id();
                    $t->unsignedBigInteger('client_id')->index();
                    $t->string('edition', 8);
                    $t->string('key_hash', 64)->unique();
                    $t->text('key_enc');                                 // Crypt — panel can re-show
                    $t->string('key_last4', 4);
                    $t->string('status', 16)->default('pending');        // pending|active|suspended|revoked
                    $t->date('amc_expires_on')->nullable();
                    $t->timestamp('activated_at')->nullable();
                    $t->string('fingerprint')->nullable();
                    $t->string('server_name')->nullable();
                    $t->unsignedInteger('reactivations_used')->default(0); // Q5: 1/year self-service
                    $t->timestamp('last_seen_at')->nullable();
                    $t->timestamps();
                });
            }
            if (! Schema::hasTable('licence_events')) {
                Schema::create('licence_events', function ($t) {
                    $t->id();
                    $t->unsignedBigInteger('licence_id')->index();
                    $t->string('type', 30);                              // generated|activated|heartbeat|deactivated|revoked|denied
                    $t->text('detail')->nullable();
                    $t->string('ip', 60)->nullable();
                    $t->timestamps();
                });
            }
            if (! Schema::hasTable('releases')) {
                Schema::create('releases', function ($t) {
                    $t->id();
                    $t->string('version', 30)->unique();
                    $t->text('notes')->nullable();                       // plain-language changelog
                    $t->string('file_path')->nullable();                 // storage path of the zip
                    $t->string('checksum', 64)->nullable();              // sha256
                    $t->unsignedBigInteger('size')->default(0);
                    $t->timestamp('published_at')->nullable();
                    $t->timestamp('applied_platform_at')->nullable();
                    $t->timestamps();
                });
            }
            if (! Schema::hasTable('release_grants')) {
                Schema::create('release_grants', function ($t) {
                    $t->id();
                    $t->unsignedBigInteger('release_id')->index();
                    $t->unsignedBigInteger('client_id')->index();
                    $t->string('granted_by')->nullable();
                    $t->timestamp('emailed_at')->nullable();
                    $t->timestamps();
                });
            }
            if (! Schema::hasTable('client_updates')) {
                Schema::create('client_updates', function ($t) {
                    $t->id();
                    $t->unsignedBigInteger('client_id')->nullable()->index();
                    $t->unsignedBigInteger('licence_id')->nullable()->index();
                    $t->string('version', 30)->nullable();
                    $t->string('action', 20);                            // check|download|applied|failed
                    $t->text('detail')->nullable();
                    $t->timestamps();
                });
            }
        } catch (\Throwable $e) {
            // fail-soft: a broken DDL must never take the platform down
        }
    }

    /** New plaintext key, e.g. SPRS-7K2M-9XQ4-PT3W-8NJB. */
    public static function generateKey(): string
    {
        $block = function () {
            $s = '';
            for ($i = 0; $i < 4; $i++) {
                $s .= self::CHARSET[random_int(0, strlen(self::CHARSET) - 1)];
            }

            return $s;
        };

        return 'SPRS-'.$block().'-'.$block().'-'.$block().'-'.$block();
    }

    public static function normalize(string $key): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $key));
    }

    public static function hashKey(string $key): string
    {
        return hash('sha256', self::normalize($key));
    }

    /** Licence row by plaintext key (null if unknown). */
    public static function findByKey(string $key): ?object
    {
        self::ensureTables();
        try {
            return DB::table('licences')->where('key_hash', self::hashKey($key))->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Create a licence for a client; returns the PLAINTEXT key (shown once + emailed). */
    public static function issue(int $clientId, string $edition, ?string $amcExpiresOn): string
    {
        self::ensureTables();
        $key = self::generateKey();
        $id = DB::table('licences')->insertGetId([
            'client_id' => $clientId,
            'edition' => $edition,
            'key_hash' => self::hashKey($key),
            'key_enc' => Crypt::encryptString($key),
            'key_last4' => substr(self::normalize($key), -4),
            'status' => 'pending',
            'amc_expires_on' => $amcExpiresOn,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        self::event($id, 'generated', 'Key issued for client #'.$clientId.' ('.$edition.')');

        return $key;
    }

    /** Plaintext key back from the panel (engineer view). */
    public static function reveal(object $licence): ?string
    {
        try {
            return Crypt::decryptString($licence->key_enc);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function amcActive(object $licence): bool
    {
        return $licence->amc_expires_on && $licence->amc_expires_on >= now()->toDateString();
    }

    public static function event($licenceId, string $type, string $detail = '', ?string $ip = null): void
    {
        try {
            DB::table('licence_events')->insert([
                'licence_id' => (int) $licenceId, 'type' => $type,
                'detail' => mb_substr($detail, 0, 2000), 'ip' => $ip,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }

    /**
     * Activation certificate the client stores locally. Signed with an HMAC
     * keyed by the licence key itself — both sides know it, nobody else does.
     */
    public static function certificate(object $licence, string $key, string $fingerprint): array
    {
        $payload = [
            'edition' => $licence->edition,
            'amc_expires_on' => $licence->amc_expires_on,
            'fingerprint' => $fingerprint,
            'issued' => now()->toDateTimeString(),
        ];
        $payload['sig'] = hash_hmac('sha256', json_encode($payload), self::normalize($key));

        return $payload;
    }
}
