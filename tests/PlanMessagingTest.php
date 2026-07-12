<?php
use PHPUnit\Framework\TestCase;

/*
 * Unit tests for url_limit_message(): the period-aware copy shown when a plan's
 * link quota is exhausted. Pure (takes a plan-config array), so no DB needed.
 */
require_once __DIR__ . '/../inc/urls.php';

final class PlanMessagingTest extends TestCase
{
    private function plan($period, $limit = 20, $name = 'Free trial')
    {
        return array('name' => $name, 'url_limit' => $limit, 'limit_period' => $period);
    }

    public function testLifetimeTotalMessageWording(): void
    {
        $msg = url_limit_message($this->plan('total', 20, 'Free trial'));
        $this->assertStringContainsString('lifetime limit of 20 links', $msg);
        $this->assertStringContainsString('Free trial plan', $msg);
        $this->assertStringContainsString('Upgrade', $msg);
        // A lifetime cap must NOT claim it resets.
        $this->assertStringNotContainsString('resets', $msg);
    }

    public function testMonthlyMessageWordingMentionsReset(): void
    {
        $msg = url_limit_message($this->plan('month', 50, 'Pro'));
        $this->assertStringContainsString("all 50 of this month's links", $msg);
        $this->assertStringContainsString('Pro plan', $msg);
        $this->assertStringContainsString('resets', $msg);
    }

    public function testLimitCountIsFormattedFromConfig(): void
    {
        $this->assertStringContainsString('100 links', url_limit_message($this->plan('total', 100, 'Custom')));
    }
}
