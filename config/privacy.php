<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Data retention (§43)
    |--------------------------------------------------------------------------
    | Default windows (days) for the daily `privacy:prune` sweep. Orgs may
    | shorten their own window via organizations.settings.audit_retention_days
    | / login_retention_days (never lengthen past these defaults).
    */

    'audit_retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),

    'login_retention_days' => (int) env('LOGIN_RETENTION_DAYS', 180),

];
