<?php
/**
 * PHP port of backend/main.py's audit logic (normalize_url, fetch, get_soup,
 * page_speed, audit_site). Behavior is kept as close as possible to the
 * original FastAPI/BeautifulSoup implementation.
 */

require_once __DIR__ . '/../config.php';

/** Raised for user-facing input problems -> HTTP 400, mirrors Python's ValueError usage. */
class CgsValueError extends RuntimeException {}

/** Raised for upstream fetch failures -> HTTP 502, mirrors requests.RequestException. */
class CgsFetchError extends RuntimeException {}

final class Audit
{
    public static function normalizeUrl(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new CgsValueError('Website URL is required.');
        }
        if (!preg_match('#^https?://#i', $raw)) {
            $raw = 'https://' . $raw;
        }
        $parsed = parse_url($raw);
        $scheme = strtolower($parsed['scheme'] ?? '');
        $host = $parsed['host'] ?? null;
        if (!in_array($scheme, ['http', 'https'], true) || !$host) {
            throw new CgsValueError('Please enter a valid http/https website URL.');
        }
        $host = strtolower(rtrim($host, '.'));
        if ($host === 'localhost' || $host === 'localhost.localdomain' || str_ends_with($host, '.local')) {
            throw new CgsValueError('Local/private addresses are not allowed.');
        }

        $ips = @dns_get_record($host, DNS_A + DNS_AAAA);
        if ($ips === false) {
            $ips = [];
        }
        // Fallback for environments where dns_get_record is restricted.
        if (empty($ips)) {
            $a = gethostbynamel($host);
            if ($a) {
                foreach ($a as $ip) {
                    $ips[] = ['ip' => $ip];
                }
            }
        }
        foreach ($ips as $rec) {
            $ip = $rec['ip'] ?? ($rec['ipv6'] ?? null);
            if (!$ip) {
                continue;
            }
            if (self::isDisallowedIp($ip)) {
                throw new CgsValueError('Private/local network addresses are not allowed.');
            }
        }
        // If DNS genuinely fails to resolve, let the later HTTP fetch surface a useful error
        // (mirrors the Python code's socket.gaierror -> pass).

        return $raw;
    }

    private static function isDisallowedIp(string $ip): bool
    {
        // Reject private, loopback, link-local, reserved, and multicast ranges.
        $publicOnly = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
        if ($publicOnly === false) {
            return true;
        }
        // FILTER_FLAG_NO_RES_RANGE does not reliably catch multicast on all PHP builds; check explicitly.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long !== false) {
                // 224.0.0.0/4 multicast
                if (($long & 0xF0000000) === 0xE0000000) {
                    return true;
                }
            }
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if (stripos($ip, 'ff') === 0) { // ff00::/8 multicast
                return true;
            }
        }
        return false;
    }

    /** @return array{0:string,1:string,2:int,3:string} [final_url, body, http_code, content_type] */
    private static function curlFetch(string $url, int $timeout = TIMEOUT, bool $head = false): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_USERAGENT => USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOBODY => $head,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new CgsFetchError($err ?: 'Request failed.');
        }
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new CgsFetchError("HTTP error {$httpCode} for {$finalUrl}");
        }

        return [$finalUrl, (string) $body, $httpCode, $contentType];
    }

    /** @return array{0:string,1:string,2:DOMDocument} [final_url, html, DOMDocument] */
    private static function getDom(string $url): array
    {
        [$finalUrl, $html, , $contentType] = self::curlFetch($url);
        if (stripos($contentType, 'html') === false) {
            throw new CgsValueError('The URL did not return an HTML page.');
        }
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        // Force UTF-8 interpretation regardless of declared charset quirks.
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        return [$finalUrl, $html, $doc];
    }

    private static function urlExists(string $url): bool
    {
        try {
            [, , $code] = self::curlFetch($url, 8);
            return $code < 400;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function pageSpeed(string $url): array
    {
        $key = trim(cgs_env('PAGESPEED_API_KEY', ''));
        $endpoint = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
        $params = [
            'url' => $url,
            'strategy' => 'mobile',
        ];
        $query = http_build_query($params) .
            '&category=performance&category=accessibility&category=best-practices&category=seo';
        if ($key !== '') {
            $query .= '&key=' . urlencode($key);
        }

        $ch = curl_init("$endpoint?$query");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 45,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['available' => false, 'error' => substr($err ?: 'request failed', 0, 160)];
        }
        if ($code >= 400) {
            return ['available' => false, 'error' => "PageSpeed HTTP $code"];
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return ['available' => false, 'error' => 'Invalid PageSpeed response'];
        }
        $cats = $data['lighthouseResult']['categories'] ?? [];
        $scoreOf = fn($cat) => (int) round((($cats[$cat]['score'] ?? 0) ?: 0) * 100);

        return [
            'available' => true,
            'performance' => $scoreOf('performance'),
            'accessibility' => $scoreOf('accessibility'),
            'best_practices' => $scoreOf('best-practices'),
            'seo' => $scoreOf('seo'),
        ];
    }

    private static function clamp(float $n, int $lo = 0, int $hi = 100): int
    {
        return max($lo, min($hi, (int) round($n)));
    }

    /**
     * Uses OpenAI to give a genuine LLM-based judgment of how well an AI
     * assistant (like ChatGPT) could understand, trust, and recommend this
     * business based on its actual page content. Falls back gracefully
     * (available=false) if no API key is configured or the call fails --
     * callers should keep the existing rule-based AI score as a fallback.
     *
     * @return array{available:bool, score?:int, summary?:string, recommendations?:array<string>, error?:string}
     */
    private static function openAiReadiness(
        string $url,
        string $title,
        string $description,
        array $h1s,
        int $wordCount,
        array $schemaTypes,
        bool $hasFaq
    ): array {
        $key = trim(cgs_env('OPENAI_API_KEY', ''));
        if ($key === '') {
            return ['available' => false, 'error' => 'No OPENAI_API_KEY configured'];
        }

        $payload = [
            'website' => $url,
            'title' => mb_substr($title, 0, 200),
            'meta_description' => mb_substr($description, 0, 300),
            'h1_headings' => array_slice($h1s, 0, 5),
            'word_count' => $wordCount,
            'schema_types_present' => $schemaTypes,
            'has_faq_content' => $hasFaq,
        ];

        $systemPrompt = 'You are an expert evaluator of "AI search readiness" -- how well an AI '
            . 'assistant such as ChatGPT, Perplexity, or Google AI Overviews could understand, '
            . 'trust, and confidently recommend a business based on the signals present on its '
            . 'website. You will be given structured facts extracted from one real webpage. '
            . 'Respond ONLY with a compact JSON object, no prose, no markdown fences, matching '
            . 'exactly this shape: {"score": <integer 0-100>, "summary": "<one sentence, '
            . 'under 30 words>", "recommendations": ["<short actionable tip>", ...]} with at '
            . 'most 4 recommendations, each under 15 words.';

        $userPrompt = "Evaluate this website's AI search readiness from these extracted signals:\n"
            . json_encode($payload, JSON_PRETTY_PRINT);

        $body = json_encode([
            'model' => cgs_env('OPENAI_MODEL', 'gpt-4o-mini'),
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => 0.3,
            'response_format' => ['type' => 'json_object'],
        ]);

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return ['available' => false, 'error' => substr($err ?: 'request failed', 0, 160)];
        }
        if ($code >= 400) {
            return ['available' => false, 'error' => "OpenAI HTTP $code: " . substr($resp, 0, 200)];
        }

        $data = json_decode($resp, true);
        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!$content) {
            return ['available' => false, 'error' => 'Empty OpenAI response'];
        }

        $parsed = json_decode($content, true);
        if (!is_array($parsed) || !isset($parsed['score'])) {
            return ['available' => false, 'error' => 'Could not parse OpenAI JSON output'];
        }

        return [
            'available' => true,
            'score' => self::clamp((float) $parsed['score']),
            'summary' => (string) ($parsed['summary'] ?? ''),
            'recommendations' => array_slice((array) ($parsed['recommendations'] ?? []), 0, 4),
        ];
    }

    /** Resolve a possibly-relative href against a base URL (urljoin equivalent). */
    private static function resolveUrl(string $base, string $href): ?string
    {
        $href = trim($href);
        if ($href === '') {
            return null;
        }
        if (preg_match('#^(https?:)?//#i', $href) || preg_match('#^[a-z][a-z0-9+.\-]*:#i', $href)) {
            // Absolute URL or another scheme (mailto:, tel:, javascript:, etc.)
            if (str_starts_with($href, '//')) {
                $baseParts = parse_url($base);
                $scheme = $baseParts['scheme'] ?? 'https';
                return "$scheme:$href";
            }
            return $href;
        }
        $baseParts = parse_url($base);
        if (!$baseParts || !isset($baseParts['host'])) {
            return null;
        }
        $scheme = $baseParts['scheme'] ?? 'https';
        $host = $baseParts['host'];
        $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
        $basePath = $baseParts['path'] ?? '/';

        if (str_starts_with($href, '/')) {
            $path = $href;
        } else {
            $dir = substr($basePath, 0, strrpos($basePath, '/') + 1) ?: '/';
            $path = $dir . $href;
        }
        // Collapse . and .. segments.
        $segments = explode('/', $path);
        $resolved = [];
        foreach ($segments as $seg) {
            if ($seg === '.' || $seg === '') {
                continue;
            }
            if ($seg === '..') {
                array_pop($resolved);
                continue;
            }
            $resolved[] = $seg;
        }
        $path = '/' . implode('/', $resolved);
        return "$scheme://$host$port$path";
    }

    private static function textContentOf(?DOMNode $node): string
    {
        if (!$node) {
            return '';
        }
        return trim(preg_replace('/\s+/u', ' ', $node->textContent));
    }

    public static function auditSite(string $url): array
    {
        [$finalUrl, , $doc] = self::getDom($url);
        $xpath = new DOMXPath($doc);

        $titleNode = $xpath->query('//title')->item(0);
        $title = self::textContentOf($titleNode);

        $description = '';
        foreach ($xpath->query('//meta[@name]') as $meta) {
            /** @var DOMElement $meta */
            if (strcasecmp($meta->getAttribute('name'), 'description') === 0) {
                $description = trim($meta->getAttribute('content'));
                break;
            }
        }

        $viewport = null;
        foreach ($xpath->query('//meta[@name]') as $meta) {
            if (strcasecmp($meta->getAttribute('name'), 'viewport') === 0) {
                $viewport = $meta;
                break;
            }
        }

        $canonical = null;
        foreach ($xpath->query('//link[@rel]') as $link) {
            if (stripos($link->getAttribute('rel'), 'canonical') !== false) {
                $canonical = $link;
                break;
            }
        }

        $h1s = [];
        foreach ($xpath->query('//h1') as $h1) {
            $h1s[] = self::textContentOf($h1);
        }
        $h2Count = $xpath->query('//h2')->length;

        $imgs = $xpath->query('//img');
        $imgsWithAlt = 0;
        foreach ($imgs as $img) {
            /** @var DOMElement $img */
            if (trim($img->getAttribute('alt')) !== '') {
                $imgsWithAlt++;
            }
        }

        $internal = [];
        $external = [];
        $finalHost = parse_url($finalUrl, PHP_URL_HOST) ?? '';
        $socialDomains = [
            'facebook' => 'facebook.com',
            'instagram' => 'instagram.com',
            'linkedin' => 'linkedin.com',
            'youtube' => 'youtube.com',
            'x' => 'x.com',
        ];
        $socials = array_fill_keys(array_keys($socialDomains), false);

        foreach ($xpath->query('//a[@href]') as $a) {
            /** @var DOMElement $a */
            $href = self::resolveUrl($finalUrl, $a->getAttribute('href'));
            if (!$href) {
                continue;
            }
            $hp = parse_url($href);
            if (!$hp) {
                continue;
            }
            $netloc = strtolower($hp['host'] ?? '');
            $scheme = strtolower($hp['scheme'] ?? '');
            if ($netloc !== '' && str_ends_with($netloc, strtolower($finalHost))) {
                $internal[] = $href;
            } elseif (in_array($scheme, ['http', 'https'], true)) {
                $external[] = $href;
            }
            foreach ($socialDomains as $name => $domain) {
                if (str_contains($netloc, $domain)) {
                    $socials[$name] = true;
                }
            }
        }

        $bodyNode = $xpath->query('//body')->item(0);
        $text = $bodyNode ? self::textContentOf($bodyNode) : self::textContentOf($doc->documentElement);
        preg_match_all("/[\\w'-]+/u", $text, $wordMatches);
        $words = $wordMatches[0];

        $schemaTexts = [];
        foreach ($xpath->query('//script[@type]') as $script) {
            /** @var DOMElement $script */
            if (preg_match('#application/ld\+json#i', $script->getAttribute('type'))) {
                $schemaTexts[] = trim(preg_replace('/\s+/u', ' ', $script->textContent));
            }
        }
        $schemaText = implode(' ', $schemaTexts);
        preg_match_all('/"@type"\s*:\s*"([^"]+)"/i', $schemaText, $typeMatches);
        $schemaTypes = array_values(array_unique($typeMatches[1] ?? []));
        sort($schemaTypes);

        $hasOrgSchema = false;
        foreach ($schemaTypes as $t) {
            if (in_array(strtolower($t), ['organization', 'localbusiness', 'corporation'], true)) {
                $hasOrgSchema = true;
                break;
            }
        }
        $hasFaqSchema = false;
        foreach ($schemaTypes as $t) {
            if (strtolower($t) === 'faqpage') {
                $hasFaqSchema = true;
                break;
            }
        }
        $hasFaq = $hasFaqSchema || (bool) preg_match('/frequently asked|faq/i', $text);
        $sameAs = (bool) preg_match('/"sameAs"\s*:/i', $schemaText);

        $ogTitle = false;
        $ogDesc = false;
        foreach ($xpath->query('//meta[@property]') as $meta) {
            /** @var DOMElement $meta */
            $prop = strtolower($meta->getAttribute('property'));
            if ($prop === 'og:title') {
                $ogTitle = true;
            }
            if ($prop === 'og:description') {
                $ogDesc = true;
            }
        }
        $hasOg = $ogTitle && $ogDesc;

        $robotsUrl = self::resolveUrl($finalUrl, '/robots.txt');
        $sitemapUrl = self::resolveUrl($finalUrl, '/sitemap.xml');
        $robotsOk = self::urlExists($robotsUrl);
        $sitemapOk = self::urlExists($sitemapUrl);

        $https = stripos($finalUrl, 'https://') === 0;
        $mobile = $viewport !== null;
        $titleLen = mb_strlen($title);
        $titleOk = $titleLen >= 30 && $titleLen <= 65;
        $descLen = mb_strlen($description);
        $descOk = $descLen >= 70 && $descLen <= 165;
        $h1Ok = count($h1s) === 1;
        $imgCount = $imgs->length;
        $altRatio = $imgCount > 0 ? ($imgsWithAlt / $imgCount) : 1.0;
        $wordCount = count($words);
        $contentOk = $wordCount >= 300;

        $psi = self::pageSpeed($finalUrl);

        $websiteChecks = [
            'HTTPS' => $https,
            'Mobile viewport' => $mobile,
            'Title tag' => $titleOk,
            'Meta description' => $descOk,
            'Single H1' => $h1Ok,
            'Canonical URL' => $canonical !== null,
            'Open Graph' => $hasOg,
            'Image alt coverage' => $altRatio >= 0.8,
            'Readable content' => $contentOk,
            'Internal links' => count($internal) >= 5,
        ];
        $seoChecks = [
            'Title optimized' => $titleOk,
            'Meta description optimized' => $descOk,
            'H1 present' => count($h1s) >= 1,
            'Canonical' => $canonical !== null,
            'Robots.txt' => $robotsOk,
            'XML sitemap' => $sitemapOk,
            'Structured data' => count($schemaTexts) > 0,
            'Content depth' => $contentOk,
        ];
        $addressSignal = (bool) preg_match('/\b(?:mumbai|delhi|pune|bangalore|bengaluru|india)\b/i', $text)
            && (bool) preg_match('/\+?\d[\d\s().-]{8,}/', $text);
        $localChecks = [
            'HTTPS' => $https,
            'LocalBusiness/Organization schema' => $hasOrgSchema,
            'Address/phone signals' => $addressSignal,
            'Social profiles linked' => array_sum($socials) >= 2,
            'FAQ content' => $hasFaq,
        ];
        $aiChecks = [
            'Organization/LocalBusiness schema' => $hasOrgSchema,
            'sameAs identity links' => $sameAs,
            'FAQ content' => $hasFaq,
            'Clear page title' => $title !== '',
            'Descriptive headings' => $h2Count >= 2,
            'Sitemap' => $sitemapOk,
            'Strong content depth' => $wordCount >= 600,
        ];
        $socialChecks = [
            'Facebook' => $socials['facebook'],
            'Instagram' => $socials['instagram'],
            'LinkedIn' => $socials['linkedin'],
            'YouTube' => $socials['youtube'],
            'X' => $socials['x'],
            'Open Graph' => $hasOg,
        ];

        $scoreChecks = function (array $checks): int {
            $total = count($checks) ?: 1;
            $hits = 0;
            foreach ($checks as $v) {
                $hits += $v ? 1 : 0;
            }
            return self::clamp(($hits / $total) * 100);
        };

        $websiteScore = $scoreChecks($websiteChecks);
        $seoScore = $scoreChecks($seoChecks);
        $localScore = $scoreChecks($localChecks);
        $aiScore = $scoreChecks($aiChecks);
        $socialScore = $scoreChecks($socialChecks);

        if ($psi['available']) {
            $websiteScore = self::clamp($websiteScore * 0.65 + $psi['performance'] * 0.35);
            $seoScore = self::clamp($seoScore * 0.75 + $psi['seo'] * 0.25);
        }

        $aiReadiness = self::openAiReadiness(
            $finalUrl, $title, $description, $h1s, $wordCount, $schemaTypes, $hasFaq
        );
        if ($aiReadiness['available']) {
            // Blend: 50% real LLM judgment, 50% existing rule-based signals.
            $aiScore = self::clamp($aiScore * 0.5 + $aiReadiness['score'] * 0.5);
        }

        $scores = [
            'Website' => $websiteScore,
            'SEO' => $seoScore,
            'Local SEO' => $localScore,
            'AI Search Readiness' => $aiScore,
            'Social Presence' => $socialScore,
        ];
        $overall = self::clamp(array_sum($scores) / count($scores));

        $recommendations = [];
        $addIf = function (bool $condition, string $title, string $detail, string $priority = 'High') use (&$recommendations) {
            if ($condition) {
                $recommendations[] = ['title' => $title, 'detail' => $detail, 'priority' => $priority];
            }
        };

        $addIf(!$https, 'Enable HTTPS', 'Use HTTPS across the entire website and redirect HTTP to HTTPS.');
        $addIf(!$mobile, 'Improve mobile readiness', 'Add a responsive viewport and verify mobile layouts.');
        $addIf(!$titleOk, 'Improve title tag', 'Create a unique, descriptive title around the main service and location.');
        $addIf(!$descOk, 'Improve meta description', 'Write a compelling 70–165 character description for important pages.');
        $addIf(!$h1Ok, 'Fix H1 structure', 'Use one clear primary H1 that explains the page topic.');
        $addIf($canonical === null, 'Add canonical URL', 'Add a canonical link to important indexable pages.');
        $addIf(!$robotsOk, 'Review robots.txt', "Publish a valid robots.txt and make sure important pages aren't blocked.", 'Medium');
        $addIf(!$sitemapOk, 'Add XML sitemap', 'Publish an XML sitemap and submit it in Search Console.', 'High');
        $addIf(count($schemaTexts) === 0, 'Add structured data', 'Use relevant Schema.org structured data such as Organization, LocalBusiness or Service.', 'High');
        $addIf(!$hasOrgSchema, 'Strengthen entity identity', 'Add Organization/LocalBusiness structured data with consistent business details.', 'High');
        $addIf(!$sameAs, 'Connect official profiles', 'Use sameAs links in structured data to connect official social profiles.', 'Medium');
        $addIf(!$hasFaq, 'Add helpful FAQs', 'Create concise FAQ content around real customer questions.', 'Medium');
        $addIf(!$hasOg, 'Improve social sharing', 'Add Open Graph title/description/image tags.', 'Low');
        $addIf($altRatio < 0.8, 'Improve image accessibility', 'Add useful alt text to meaningful images.', 'Medium');
        $addIf($wordCount < 600, 'Expand useful content', 'Create deeper service, location and FAQ content where it helps customers.', 'Medium');
        $addIf(array_sum($socials) < 3, 'Strengthen social presence', 'Link and maintain the official social profiles that matter to the business.', 'Medium');

        if ($psi['available']) {
            if ($psi['performance'] < 70) {
                $addIf(true, 'Improve mobile performance', "PageSpeed mobile performance is {$psi['performance']}/100. Optimize images, scripts and loading.", 'High');
            }
            if ($psi['accessibility'] < 80) {
                $addIf(true, 'Improve accessibility', "Accessibility score is {$psi['accessibility']}/100. Review Lighthouse accessibility findings.", 'Medium');
            }
            if ($psi['best_practices'] < 80) {
                $addIf(true, 'Review best practices', "Best-practices score is {$psi['best_practices']}/100.", 'Medium');
            }
        }

        if ($aiReadiness['available']) {
            foreach ($aiReadiness['recommendations'] as $tip) {
                $addIf(true, 'AI readiness: ' . mb_substr((string) $tip, 0, 60), (string) $tip, 'High');
            }
        }

        // Deduplicate recommendation titles, preserving order, capped at 10.
        $seen = [];
        $deduped = [];
        foreach ($recommendations as $r) {
            if (isset($seen[$r['title']])) {
                continue;
            }
            $seen[$r['title']] = true;
            $deduped[] = $r;
            if (count($deduped) >= 10) {
                break;
            }
        }

        return [
            'website' => $finalUrl,
            'business_name' => '',
            'city' => '',
            'industry' => '',
            'generated_at' => (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
            'overall' => $overall,
            'scores' => $scores,
            'page' => [
                'title' => $title,
                'description' => $description,
                'h1_count' => count($h1s),
                'h1s' => array_slice($h1s, 0, 5),
                'word_count' => $wordCount,
                'images' => $imgCount,
                'images_with_alt' => $imgsWithAlt,
                'internal_links' => count($internal),
                'external_links' => count($external),
                'schema_types' => array_slice($schemaTypes, 0, 20),
                'socials' => $socials,
                'robots' => $robotsOk,
                'sitemap' => $sitemapOk,
            ],
            'pagespeed' => $psi,
            'ai_readiness' => $aiReadiness,
            'recommendations' => $deduped,
            'disclaimer' => 'This is an automated screening audit, not a guarantee of search rankings, leads, revenue, or AI-search placement. Results can vary by page, device, query, location and time.',
        ];
    }
}
