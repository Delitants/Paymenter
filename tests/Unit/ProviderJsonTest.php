<?php

namespace Tests\Unit;

use App\Services\Gateways\Operations\ProviderJson;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProviderJsonTest extends TestCase
{
    public function test_wire_money_is_preserved_without_touching_quoted_or_escaped_text(): void
    {
        $json = <<<'JSON'
{"amount":109.88,"units":10988,"zero":0.00,"large":123456789012345678901234567890,"exponent":1.0988e2,"text":"quote \"109.88\" and slash \\ 1.00","nested":[true,null,-0.01]}
JSON;
        $decoded = ProviderJson::decode($json);
        $this->assertSame('109.88', $decoded['amount']);
        $this->assertSame(10988, $decoded['units']);
        $this->assertSame('0.00', $decoded['zero']);
        $this->assertSame('123456789012345678901234567890', $decoded['large']);
        $this->assertSame('1.0988e2', $decoded['exponent']);
        $this->assertSame('quote "109.88" and slash \\ 1.00', $decoded['text']);
        $this->assertSame([true, null, '-0.01'], $decoded['nested']);
    }

    public function test_invalid_numeric_grammar_is_not_repaired(): void
    {
        foreach (['{"amount":01.23}', '{"amount":1.}', '{"amount":NaN}', '{"amount":1e}', '{"amount":1.23,}', '{"amount":"unterminated}', '{"amount":1.23} garbage'] as $json) {
            try {
                ProviderJson::decode($json);
                $this->fail('Invalid provider JSON was repaired');
            } catch (RuntimeException $error) {
                $this->assertSame('Provider JSON could not be verified.', $error->getMessage());
            }
        }
    }
}
