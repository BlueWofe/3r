# Ministry backend
Follow ../AGENTS.md and ../docs/contract.md. Run PHP and Composer inside the supported container; no host runtime installation is needed. Format PHP with vendor/bin/pint. Run php artisan test. Never expose OTP mailbox, local storage paths, secrets, or real personal data. Backend routes use the web middleware group for session and CSRF protection.
