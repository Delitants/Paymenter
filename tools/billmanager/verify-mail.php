<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

// Authenticate and disconnect. This probe never submits a message.
if (PHP_SAPI !== 'cli' || count($argv) !== 3) {
    throw new RuntimeException('Usage: verify-mail.php ENCRYPTED_BUNDLE EXPECTED_SOURCE');
}
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$bundle = json_decode(Crypt::decryptString(file_get_contents($argv[1])), true, flags: JSON_THROW_ON_ERROR);
if (($bundle['kind'] ?? null) !== 'mail_settings' || ($bundle['source_host'] ?? null) !== $argv[2]) {
    throw new RuntimeException('Unexpected mail bundle');
}
$settings = $bundle['settings'];
$transport = new EsmtpTransport($settings['mail_host'], (int) $settings['mail_port'], $settings['mail_encryption'] === 'ssl');
$transport->getStream()->setTimeout(15);
$transport->setUsername($settings['mail_username']);
$transport->setPassword($settings['mail_password']);
try {
    $transport->start();
    $transport->stop();
    echo json_encode(['smtp_authentication' => 'passed', 'messages_sent' => 0]), PHP_EOL;
} catch (Throwable $e) {
    // SMTP diagnostics may echo authentication material; never print them.
    echo json_encode(['smtp_authentication' => 'failed', 'error_class' => get_class($e), 'code' => $e->getCode(), 'messages_sent' => 0]), PHP_EOL;
    exit(1);
}
