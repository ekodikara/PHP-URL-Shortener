<?php
use PHPUnit\Framework\TestCase;

/*
 * Unit tests for the pure analytics helpers (range buckets + the privacy
 * visitor hash). DB-backed aggregation is verified end-to-end in a container.
 */
if (!defined('ANALYTICS_SALT')) { define('ANALYTICS_SALT', 'test-salt'); }
require_once __DIR__ . '/../inc/analytics.php';

final class AnalyticsTest extends TestCase
{
    public function testRangeKnownBuckets(): void
    {
        list($since7, $days7, $label7) = analytics_range('7');
        $this->assertSame(7, $days7);
        $this->assertSame('Last 7 days', $label7);
        $this->assertGreaterThan(0, $since7);

        list($sinceAll, $daysAll, $labelAll) = analytics_range('all');
        $this->assertSame(0, $sinceAll);          // all-time = no lower bound
        $this->assertSame(0, $daysAll);
        $this->assertSame('All time', $labelAll);
    }

    public function testRangeUnknownDefaultsTo30(): void
    {
        list(, $days, $label) = analytics_range('bogus');
        $this->assertSame(30, $days);
        $this->assertSame('Last 30 days', $label);
    }

    public function testVisitorHashDeterministicAndScoped(): void
    {
        $a = visitor_hash('1.2.3.4', 'UA/1', 'abc');
        $b = visitor_hash('1.2.3.4', 'UA/1', 'abc');
        $this->assertSame($a, $b, 'same inputs -> same hash (dedup works)');
        $this->assertSame(64, strlen($a), 'sha256 hex');

        // Scoped per link: same visitor, different code -> different hash.
        $this->assertNotSame($a, visitor_hash('1.2.3.4', 'UA/1', 'xyz'));
        // Different visitor -> different hash.
        $this->assertNotSame($a, visitor_hash('9.9.9.9', 'UA/1', 'abc'));
        // Raw IP is never recoverable from the hash.
        $this->assertStringNotContainsString('1.2.3.4', $a);
    }
}
