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
    | Money
    |--------------------------------------------------------------------------
    | All monetary values use decimal columns + integer-safe math. Never float.
    */
    'currency' => 'INR',
    'money_scale' => 2,
];
