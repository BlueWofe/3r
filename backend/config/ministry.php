<?php

return ['sms_driver' => env('OTP_DRIVER', env('SMS_DRIVER', 'mock')), 'otp_test_phones' => env('OTP_TEST_PHONES', ''), 'mitake_username' => env('MITAKE_USERNAME'), 'mitake_password' => env('MITAKE_PASSWORD'), 'demo_seed' => env('DEMO_SEED', false), 'demo_password' => env('DEMO_PASSWORD')];
