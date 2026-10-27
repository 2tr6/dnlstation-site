<?php
// Yalnızca yerel test ayarı. Üretimde kullanılmaz.
return [
    'app_url' => 'http://127.0.0.1:8790',
    'app_key' => 'dGVzdC1rZXktdGVzdC1rZXktdGVzdC1rZXktdGVzdC1rZXktMTIzNDU2Nzg5MA==',
    'db' => ['driver' => 'sqlite', 'path' => __DIR__ . '/tmp/test.db'],
    'mail' => ['transport' => 'file', 'from' => 'noreply@dnlstation.com.tr', 'from_name' => 'DNL Station'],
    'migrate_token' => 'test-migrate-token-0123456789abcdef0123456789',
    'log_dir' => __DIR__ . '/tmp/logs',
    'sms' => ['provider' => 'file', 'signup_otp' => false],
    'debug' => true,
];
