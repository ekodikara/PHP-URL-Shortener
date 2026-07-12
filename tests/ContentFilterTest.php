<?php
use PHPUnit\Framework\TestCase;

/*
 * Unit tests for the PURE (no DB, no network) parts of the content filter:
 * URL/host parsing, registrable-domain extraction, hosts-file blocklist
 * parsing, the keyword backstop, and admin-domain normalization.
 */

// The module reads these constants; the test bootstrap doesn't define them.
if (!defined('CONTENT_FILTER_ON')) { define('CONTENT_FILTER_ON', true); }
if (!defined('ADULT_BLOCKLIST'))   { define('ADULT_BLOCKLIST', ''); }
if (!defined('IPQS_API_KEY'))      { define('IPQS_API_KEY', ''); }
if (!defined('URL_SCAN_TTL'))      { define('URL_SCAN_TTL', 43200); }
require_once __DIR__ . '/../inc/content_filter.php';

final class ContentFilterTest extends TestCase
{
    // --- url_host -----------------------------------------------------------

    public function testUrlHostLowercasesAndStripsPath(): void
    {
        $this->assertSame('example.com', url_host('https://Example.COM/some/path?q=1'));
        $this->assertSame('sub.example.com', url_host('http://sub.example.com'));
        $this->assertSame('', url_host('not a url'));
    }

    // --- registrable_domain -------------------------------------------------

    public function testRegistrableDomainSimple(): void
    {
        $this->assertSame('example.com', registrable_domain('example.com'));
        $this->assertSame('example.com', registrable_domain('a.b.example.com'));
    }

    public function testRegistrableDomainTwoLabelSuffix(): void
    {
        $this->assertSame('example.co.uk', registrable_domain('www.example.co.uk'));
        $this->assertSame('example.com.au', registrable_domain('shop.example.com.au'));
    }

    // --- parse_hosts_blocklist ---------------------------------------------

    public function testParseHostsBlocklistFormats(): void
    {
        $set = parse_hosts_blocklist(
            "# comment\n" .
            "0.0.0.0 badporn.com\n" .
            "127.0.0.1 xxxsite.net\n" .
            "bare-domain.org\n" .
            "\n" .
            "0.0.0.0 0.0.0.0\n" .        // IP token — dropped
            "0.0.0.0 localhost\n"        // localhost — dropped
        );
        $this->assertArrayHasKey('badporn.com', $set);
        $this->assertArrayHasKey('xxxsite.net', $set);
        $this->assertArrayHasKey('bare-domain.org', $set);
        $this->assertArrayNotHasKey('0.0.0.0', $set);
        $this->assertArrayNotHasKey('localhost', $set);
        $this->assertCount(3, $set);
    }

    // --- url_keyword_flag (word-bounded) -----------------------------------

    public function testKeywordFlagMatchesObviousTokens(): void
    {
        $this->assertTrue(url_keyword_flag('https://free-porn.example.com/'));
        $this->assertTrue(url_keyword_flag('https://example.com/xxx/gallery'));
        $this->assertTrue(url_keyword_flag('https://cdn.example.com/nsfw-content'));
    }

    public function testKeywordFlagAvoidsSubstringFalsePositives(): void
    {
        // "essex"/"sussex" contain "sex" but must NOT trip the word-bounded match.
        $this->assertFalse(url_keyword_flag('https://essex-council.gov.uk/'));
        $this->assertFalse(url_keyword_flag('https://sussex.ac.uk/admissions'));
        $this->assertFalse(url_keyword_flag('https://github.com/some/repo'));
    }

    // --- normalize_blockable_host ------------------------------------------

    public function testNormalizeStripsSchemePathWww(): void
    {
        $this->assertSame('example.com', normalize_blockable_host('https://www.Example.com/path?x=1'));
        $this->assertSame('example.com', normalize_blockable_host('  example.com  '));
        $this->assertSame('sub.example.com', normalize_blockable_host('sub.example.com:8080'));
    }

    public function testNormalizeRejectsInvalid(): void
    {
        $this->assertSame('', normalize_blockable_host('not a domain'));
        $this->assertSame('', normalize_blockable_host('localhost'));
        $this->assertSame('', normalize_blockable_host(''));
    }

    // --- url_host trailing-dot canonicalization (security regression) --------

    public function testUrlHostStripsTrailingRootDot(): void
    {
        // "pornhub.com." resolves to the same host — must canonicalize so it
        // can't dodge an exact blocklist match.
        $this->assertSame('pornhub.com', url_host('https://pornhub.com./path'));
        $this->assertSame('example.co.uk', url_host('https://Example.CO.UK./'));
    }

    // --- domain_is_blocked matching (security regressions) -------------------

    private function pdoWithBlocked(array $domains): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE blocked_domains (id INTEGER PRIMARY KEY, domain TEXT)');
        foreach ($domains as $d) {
            $pdo->prepare('INSERT INTO blocked_domains (domain) VALUES (?)')->execute(array($d));
        }
        return $pdo;   // ADULT_BLOCKLIST='' in this suite → only the admin layer is active
    }

    public function testAdminBlockedRegistrableDomainCoversSubdomains(): void
    {
        $pdo = $this->pdoWithBlocked(array('badsite.com'));
        $this->assertTrue(domain_is_blocked($pdo, 'x.y.badsite.com'));
        $this->assertFalse(domain_is_blocked($pdo, 'notbadsite.com'));
    }

    public function testAdminBlockedSubdomainCoversDeeperSubdomains(): void
    {
        // Regression: a subdomain-specific admin block must also catch deeper subs.
        $pdo = $this->pdoWithBlocked(array('videos.badsite.com'));
        $this->assertTrue(domain_is_blocked($pdo, 'x.videos.badsite.com'));
        $this->assertFalse(domain_is_blocked($pdo, 'other.badsite.com'));
    }

    public function testTrailingDotHostIsCanonicalized(): void
    {
        // Regression: "badsite.com." must match an entry for "badsite.com".
        $pdo = $this->pdoWithBlocked(array('badsite.com'));
        $this->assertTrue(domain_is_blocked($pdo, 'badsite.com.'));
    }
}
