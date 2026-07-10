<?php
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure business logic that gates money and link creation:
 * pricing math, money formatting, slug validation, and code generation.
 */
final class HelpersTest extends TestCase
{
    // --- pricing math (drives the Stripe line-item amount) -------------------

    public function testMonthlyAmountIsThePlanPrice(): void
    {
        $this->assertSame(7, plan_amount('pro', 'month'));
        $this->assertSame(12, plan_amount('premium', 'month'));
    }

    public function testYearlyAppliesTwelvePercentDiscount(): void
    {
        // 7 * 12 * (1 - 0.12) = 73.92 ; 12 * 12 * 0.88 = 126.72
        $this->assertSame(73.92, plan_amount('pro', 'year'));
        $this->assertSame(126.72, plan_amount('premium', 'year'));
    }

    public function testAmountInCentsIsIntegerAndExact(): void
    {
        $this->assertSame(7392, plan_amount_cents('pro', 'year'));
        $this->assertSame(700, plan_amount_cents('pro', 'month'));
        $this->assertSame(12672, plan_amount_cents('premium', 'year'));
    }

    public function testUnknownPlanFallsBackToFree(): void
    {
        $this->assertSame(0, plan_amount('does-not-exist', 'month'));
    }

    // --- money formatting ----------------------------------------------------

    public function testMoneyDropsCentsForWholeAmounts(): void
    {
        $this->assertSame('7', money(7));
        $this->assertSame('0', money(0));
    }

    public function testMoneyKeepsTwoDecimalsWhenNeeded(): void
    {
        $this->assertSame('73.92', money(73.92));
        $this->assertSame('126.72', money(126.72));
    }

    // --- slug validation (custom link names) ---------------------------------

    public function testValidSlugsAreAccepted(): void
    {
        $this->assertTrue(is_valid_slug('my-link'));
        $this->assertTrue(is_valid_slug('Launch_2026'));
        $this->assertTrue(is_valid_slug(str_repeat('a', 40)));   // max length
    }

    public function testTooShortOrTooLongSlugsRejected(): void
    {
        $this->assertFalse(is_valid_slug('ab'));                  // < 3
        $this->assertFalse(is_valid_slug(str_repeat('a', 41)));   // > 40
    }

    public function testSlugsWithIllegalCharsRejected(): void
    {
        $this->assertFalse(is_valid_slug('has space'));
        $this->assertFalse(is_valid_slug('slash/here'));
        $this->assertFalse(is_valid_slug('dot.dot'));
    }

    public function testReservedSlugsRejectedCaseInsensitively(): void
    {
        $this->assertFalse(is_valid_slug('admin'));
        $this->assertFalse(is_valid_slug('ADMIN'));
        $this->assertFalse(is_valid_slug('Dashboard'));
    }

    // --- random code generation ----------------------------------------------

    public function testRandomCodeLengthAndCharset(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $code = random_code();
            $this->assertSame(CODE_LENGTH, strlen($code));
            $this->assertSame(1, preg_match('/^[0-9a-zA-Z]{6}$/', $code));
        }
    }

    public function testRandomCodeRespectsRequestedLength(): void
    {
        $this->assertSame(10, strlen(random_code(10)));
    }
}
