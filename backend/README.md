# Ministry API
Laravel 13, PHP 8.3+, PostgreSQL 17. All routes use /api/v1, web sessions, and CSRF. Container configuration is maintained in the repository root.

Set APP_KEY, DB credentials, and session security through environment variables. Set DEMO_SEED=true and DEMO_PASSWORD (at least 10 characters) explicitly before seeding fictional demo accounts. The default seed operation is empty.

SMS_DRIVER=mock requires OTP_TEST_PHONES as a comma-separated allowlist. Test OTP codes are saved only in private storage/app/private/otp-mailbox. Never serve this directory. Mitake credentials are server-side environment variables; the driver must be explicitly changed to mitake.

Run migrations and tests:
  php artisan migrate --force
  php artisan db:seed --force
  php artisan test
  vendor/bin/pint --test

Scheduling mutations serialize on PostgreSQL row locks and require the current session version. Attendance uses the entire Taiwan service date. Donations, LINE, and Drive are simulations; no real messages or charges occur in mock mode.

Mitake response acceptance follows the official API manual (statuscode 0, 1, 2, or 4): https://sms.mitake.com.tw/download/B2C_MitakeAPI_v2.14.pdf . OTP_DRIVER is canonical; SMS_DRIVER remains a compatible alias. HTTP 200 alone never means successful delivery.
