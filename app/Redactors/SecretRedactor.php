<?php

namespace App\Redactors;

use OwenIt\Auditing\Contracts\AttributeRedactor;

final class SecretRedactor implements AttributeRedactor
{
    public static function redact($value): string
    {
        return '[REDACTED]';
    }
}
