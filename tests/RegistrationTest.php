<?php
use PHPUnit\Framework\TestCase;

/*
 * Unit tests for the disposable-email registration guard. Uses the real vendored
 * blocklist so it also catches an accidentally-empty/renamed data file.
 */
if (!defined('BLOCK_DISPOSABLE_EMAIL')) { define('BLOCK_DISPOSABLE_EMAIL', true); }
if (!defined('DISPOSABLE_EMAIL_LIST'))  { define('DISPOSABLE_EMAIL_LIST', __DIR__ . '/../data/disposable-email-domains.txt'); }
require_once __DIR__ . '/../inc/content_filter.php';   // parse_hosts_blocklist + registrable_domain
require_once __DIR__ . '/../inc/security.php';         // is_disposable_email + disposable_email_domains

final class RegistrationTest extends TestCase
{
    public function testBlocklistLoads(): void
    {
        $this->assertGreaterThan(1000, count(disposable_email_domains()), 'vendored disposable list should be populated');
    }

    public function testKnownDisposableProvidersBlocked(): void
    {
        $this->assertTrue(is_disposable_email('bot@mailinator.com'));
        $this->assertTrue(is_disposable_email('x@guerrillamail.com'));
        // subdomain of a throwaway provider still caught (registrable-domain match)
        $this->assertTrue(is_disposable_email('x@relay.guerrillamail.com'));
    }

    public function testLegitimateProvidersAllowed(): void
    {
        $this->assertFalse(is_disposable_email('alice@gmail.com'));
        $this->assertFalse(is_disposable_email('ceo@moonxt.com'));
        $this->assertFalse(is_disposable_email('no-at-sign'));
        $this->assertFalse(is_disposable_email(''));
    }
}
