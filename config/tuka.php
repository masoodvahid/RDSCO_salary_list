<?php

return [

    /*
    | One-time passwords (login and approval signatures).
    */
    'otp' => [
        'ttl' => (int) env('OTP_TTL_SECONDS', 120),
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
        'resend' => (int) env('OTP_RESEND_SECONDS', 60),
        'length' => 6,
    ],

    /*
    | Project-level editing closes at the end of this day of the following
    | Jalali month. Managers can change the deadline per sheet.
    */
    'deadline_day' => 14,

    /*
    | Invite links only identify the invitee; login still requires an SMS code.
    */
    'invite_ttl_days' => (int) env('TUKA_INVITE_TTL_DAYS', 30),

    /*
    | Seed data. The demo users/sheet are created only outside production.
    */
    'test_admin_enabled' => (bool) env('TUKA_TEST_ADMIN_ENABLED', false),

    'admin' => [
        'name' => env('TUKA_ADMIN_NAME'),
        'mobile' => env('TUKA_ADMIN_MOBILE'),
    ],

];
