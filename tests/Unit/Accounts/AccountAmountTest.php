<?php

namespace Tests\Unit\Accounts;

use App\Services\Accounts\AccountAmount;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class AccountAmountTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertTrue(class_exists(AccountAmount::class), 'Exact account money contract is missing');
    }

    public function test_fractional_debt_is_never_rounded_up_for_spending(): void
    {
        $repaid = AccountAmount::parse('-40.0050')->add(AccountAmount::parse('50.00'));
        self::assertSame('9.9950', $repaid->exact());
        self::assertSame('9.99', $repaid->floorCents());
        self::assertSame('59.99', AccountAmount::parse('100.00')->subtract(AccountAmount::parse('40.0050'))->floorCents());
    }

    public function test_exact_amounts_and_comparison_preserve_four_decimals(): void
    {
        self::assertSame('0.0000', AccountAmount::parse('-0.0000')->exact());
        self::assertSame('1.2000', AccountAmount::parse('1.2')->exact());
        self::assertSame(-1, AccountAmount::parse('-0.0001')->compare(AccountAmount::parse('0')));
        self::assertSame(0, AccountAmount::parse('1')->compare(AccountAmount::parse('1.0000')));
        self::assertSame(1, AccountAmount::parse('1.0001')->compare(AccountAmount::parse('1')));
        self::assertSame('-0.0001', AccountAmount::parse('1')->subtract(AccountAmount::parse('1.0001'))->exact());
    }

    public function test_positive_opening_cash_uses_half_up_but_headroom_floors(): void
    {
        self::assertSame('1.01', AccountAmount::parse('1.0050')->halfUpCents());
        self::assertSame('1.00', AccountAmount::parse('1.0050')->floorCents());
        self::assertSame('0.01', AccountAmount::parse('0.0050')->halfUpCents());
        self::assertSame('0.00', AccountAmount::parse('0.0099')->floorCents());
        self::assertSame('999999999999999.99', AccountAmount::parse('999999999999999.9949')->halfUpCents());
    }

    #[DataProvider('invalidExactAmounts')]
    public function test_invalid_exact_amount_is_rejected(string $amount): void
    {
        $this->expectException(DomainException::class);
        AccountAmount::parse($amount);
    }

    public static function invalidExactAmounts(): array
    {
        return array_map(fn ($v) => [$v], ['1e3', 'NaN', 'INF', '+1', ' 1', '1 ', '.1', '1.', '01', '', '1.00001', '1000000000000000', '-1000000000000000', '1,000', "1\n", '−1']);
    }

    #[DataProvider('nonStringAmounts')]
    public function test_non_string_money_cannot_be_silently_coerced(mixed $amount): void
    {
        $this->expectException(DomainException::class);
        AccountAmount::parse($amount);
    }

    public static function nonStringAmounts(): array
    {
        return [[1], [1.01], [true], [false], [null], [[]]];
    }

    #[DataProvider('invalidFundingAmounts')]
    public function test_funding_requires_positive_cent_amount(string $amount): void
    {
        $this->expectException(DomainException::class);
        AccountAmount::positiveCents($amount);
    }

    public static function invalidFundingAmounts(): array
    {
        return array_map(fn ($v) => [$v], ['0', '0.00', '-0.00', '-1.00', '1.001', '1.0000', '1e2']);
    }

    public function test_funding_and_limit_validators_are_exact(): void
    {
        self::assertSame('0.0100', AccountAmount::positiveCents('0.01')->exact());
        self::assertSame('100.0000', AccountAmount::nonnegative('100')->exact());
        self::assertSame('0.0000', AccountAmount::nonnegative('0')->exact());
    }

    public function test_negative_limit_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        AccountAmount::nonnegative('-0.0001');
    }

    public function test_addition_overflow_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        AccountAmount::parse('999999999999999.9999')->add(AccountAmount::parse('0.0001'));
    }

    public function test_subtraction_underflow_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        AccountAmount::parse('-999999999999999.9999')->subtract(AccountAmount::parse('0.0001'));
    }

    public function test_rounded_opening_overflow_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        AccountAmount::parse('999999999999999.9999')->halfUpCents();
    }

    public function test_negative_debt_cannot_be_rounded_as_positive_cash(): void
    {
        $this->expectException(DomainException::class);
        AccountAmount::parse('-0.0001')->halfUpCents();
    }

    public function test_negative_amount_cannot_be_floored_as_positive_headroom(): void
    {
        $this->expectException(DomainException::class);
        AccountAmount::parse('-0.0001')->floorCents();
    }

    public function test_exact_money_works_with_native_integers_without_bcmath_calls(): void
    {
        $code = 'require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';
            Brick\\Math\\Internal\\CalculatorRegistry::set(new Brick\\Math\\Internal\\Calculator\\NativeCalculator);
            $amount = App\\Services\\Accounts\\AccountAmount::parse("-40.0050")->add(App\\Services\\Accounts\\AccountAmount::parse("50.00"));
            echo $amount->exact(), "|", $amount->floorCents();';
        $process = new Process([PHP_BINARY, '-d', 'disable_functions=bcadd,bcsub,bccomp,bcmul,bcdiv,bcmod,bcscale,bcnew,bcpow,bcpowmod,bcsqrt', '-r', $code]);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('9.9950|9.99', $process->getOutput());
    }
}
