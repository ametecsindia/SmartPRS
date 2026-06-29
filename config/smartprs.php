<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Deployment model
    |--------------------------------------------------------------------------
    | 'saas'   => multi-tenant hosted (company scoping enforced globally)
    | 'onprem' => single-tenant installed perpetual licence
    */
    'deployment' => env('SMARTPRS_DEPLOYMENT', 'saas'),

    /*
    |--------------------------------------------------------------------------
    | Edition (rev 103 — module licensing levels)
    |--------------------------------------------------------------------------
    | 'saas' => hosted, full app + SaaS Platform module
    | 'l1'   => on-prem Core HR   | 'l2' => + Advanced | 'l3' => + DNA modules
    | Read via App\Services\Edition (config-first, so config:cache is safe).
    */
    'edition' => env('SMARTPRS_EDITION', 'saas'),

    /*
    |--------------------------------------------------------------------------
    | Team demo PIN (rev 105)
    |--------------------------------------------------------------------------
    | Unlocks the UNRESTRICTED personal demos at /teamdemo, /app1, /app2,
    | /app3 (no demo write-guard, no hidden screens). Known to the Ametecs
    | sales team only; the public /demo stays OTP-gated and restricted.
    */
    'team_pin' => env('SMARTPRS_TEAM_PIN', 'ametecs'),

    /*
    |--------------------------------------------------------------------------
    | Version & update channel (rev 107 — Update & Licensing system)
    |--------------------------------------------------------------------------
    | 'version'    bumped on every release (BUILD-RELEASE.bat reads it).
    | 'update_url' the platform update server every on-prem client calls;
    |              baked-in default per the SRS, overridable for testing.
    | 'licence_enforce' lets a dev/demo install skip the activation gate.
    */
    'version' => '2026.6.1',
    'update_url' => env('SMARTPRS_UPDATE_URL', 'https://smartprs.com/update'),
    'licence_enforce' => env('SMARTPRS_LICENCE_ENFORCE', true),

    /*
    | Shared secret for OFFLINE self-contained License Codes (rev146). The
    | Super Admin signs a key with it; the client verifies the signature
    | locally — no server needed. It MUST be identical on the Super Admin and
    | on every client install (it is the same baked default unless overridden;
    | if you set SMARTPRS_LICENCE_SECRET, set the SAME value everywhere).
    */
    'licence_secret' => env('SMARTPRS_LICENCE_SECRET', 'SmartPRS-Ametecs-Offline-LC-v1-9f3c7a2e8b6d41f5'),

    'default_company_code' => env('SMARTPRS_DEFAULT_COMPANY_CODE', 'DEMO'),

    /*
    |--------------------------------------------------------------------------
    | Roles (locked — see CONTEXT-HANDOFF.md)
    |--------------------------------------------------------------------------
    */
    'roles' => [
        'super_admin' => 'Super Admin',   // SaaS owner — cross-tenant
        'admin'       => 'Admin',          // company owner/admin
        'hr_manager'  => 'HR Manager',
        'field_agent' => 'Field Agent',
        'employee'    => 'Employee',
    ],

    /*
    |--------------------------------------------------------------------------
    | ZKTeco biometric devices
    |--------------------------------------------------------------------------
    */
    'zkteco' => [
        'default_port' => (int) env('ZKTECO_DEFAULT_PORT', 4370),
        'sync_enabled' => (bool) env('ZKTECO_SYNC_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | eTimeOffice cloud attendance (api.etimeoffice.com)
    |--------------------------------------------------------------------------
    | HTTP Basic auth where the *username* is "CorpID:User:Password:true" and the
    | *password* is the password. Punches are pulled by emp_code and written into
    | attendance_logs. Credentials live in .env (never shipped to clients).
    */
    'etimeoffice' => [
        'enabled'  => (bool) env('ETIMEOFFICE_ENABLED', false),
        'base_url' => env('ETIMEOFFICE_BASE_URL', 'https://api.etimeoffice.com/api'),
        'endpoint' => env('ETIMEOFFICE_ENDPOINT', 'DownloadPunchDataMCID'),
        'corp_id'  => env('ETIMEOFFICE_CORP_ID'),
        'username' => env('ETIMEOFFICE_USERNAME'),
        'password' => env('ETIMEOFFICE_PASSWORD'),
        'empcode'  => env('ETIMEOFFICE_EMPCODE', 'ALL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Money
    |--------------------------------------------------------------------------
    | All monetary values use decimal columns + integer-safe math. Never float.
    */
    'currency' => 'INR',
    'money_scale' => 2,
];
