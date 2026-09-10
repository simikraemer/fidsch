<?php
// check/RWTHjobs.php
// RWTH-Stellenmonitor: öffentliche Ausschreibungen abrufen, speichern und bewerten.

if (!defined('RWTH_JOBS_CMS_URL')) {
    // Serverseitig gerenderte RWTH-Stellenliste. showall=1 reduziert den Discovery-Fetch normalerweise auf eine Seite.
    define('RWTH_JOBS_CMS_URL', 'https://www.rwth-aachen.de/cms/root/Die-RWTH/Arbeiten-an-der-RWTH/~buym/RWTH-Jobportal/?showall=1');
}

if (!defined('RWTH_JOBS_CMS_FALLBACK_URL')) {
    // Zweite/neuere URL-Alias der gleichen CMS-Stellenliste.
    define('RWTH_JOBS_CMS_FALLBACK_URL', 'https://www.rwth-aachen.de/cms/root/wir/karriere/~buym/rwth-jobportal/?showall=1');
}

if (!function_exists('rwthJobsNowBerlin')) {
    function rwthJobsNowBerlin(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    }
}

if (!function_exists('rwthJobsCleanText')) {
    function rwthJobsCleanText(?string $text): string
    {
        $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\xC2\xAD", "\xC2\xA0"], ['', ' '], $text); // Soft-Hyphen / NBSP
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\t\f]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/[ ]{2,}/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n[ ]+/u', "\n", $text) ?? $text;
        $text = preg_replace('/[ ]+\n/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        return trim($text);
    }
}

if (!function_exists('rwthJobsLower')) {
    function rwthJobsLower(string $text): string
    {
        $text = rwthJobsCleanText($text);
        return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }
}

if (!function_exists('rwthJobsDom')) {
    function rwthJobsDom(string $html): DOMDocument
    {
        if (!class_exists('DOMDocument')) {
            throw new RuntimeException('PHP-DOM/XML ist nicht verfügbar. Bitte das PHP-XML-Paket installieren.');
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET | LIBXML_COMPACT
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $dom;
    }
}

if (!function_exists('rwthJobsAbsoluteUrl')) {
    function rwthJobsAbsoluteUrl(string $base, string $relative): string
    {
        $relative = html_entity_decode(trim($relative), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($relative === '') {
            return $base;
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $relative)) {
            return $relative;
        }
        if (str_starts_with($relative, '//')) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $relative;
        }

        $bp = parse_url($base);
        if (!$bp || empty($bp['host'])) {
            return $relative;
        }

        $scheme = $bp['scheme'] ?? 'https';
        $host = $bp['host'];
        $port = isset($bp['port']) ? ':' . $bp['port'] : '';
        $origin = $scheme . '://' . $host . $port;
        $basePath = $bp['path'] ?? '/';

        if (str_starts_with($relative, '#')) {
            return $origin . $basePath . (!empty($bp['query']) ? '?' . $bp['query'] : '') . $relative;
        }
        if (str_starts_with($relative, '?')) {
            return $origin . $basePath . $relative;
        }
        if (str_starts_with($relative, '/')) {
            return $origin . $relative;
        }

        $dir = preg_replace('~/[^/]*$~', '/', $basePath) ?: '/';
        $path = $dir . $relative;

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return $scheme . '://' . $host . $port . '/' . implode('/', $segments);
    }
}

if (!function_exists('rwthJobsHttpRequest')) {
    function rwthJobsHttpRequest(
        string $url,
        string $method = 'GET',
        array $data = [],
        ?string $cookieFile = null,
        int $timeout = 20
    ): array {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP-cURL ist nicht verfügbar. Bitte das PHP-cURL-Paket installieren.');
        }

        $ch = curl_init();
        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: de-DE,de;q=0.9,en;q=0.6',
            'Cache-Control: no-cache',
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 6,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'FIJI-RWTHJobs/1.0 (private personal job tracker)',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($cookieFile !== null) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        }

        $method = strtoupper($method);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&', PHP_QUERY_RFC3986));
            curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, [
                'Content-Type: application/x-www-form-urlencoded',
            ]));
        }

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        return [
            'ok' => $body !== false && $status >= 200 && $status < 400,
            'status' => $status,
            'body' => $body === false ? '' : (string)$body,
            'url' => $finalUrl !== '' ? $finalUrl : $url,
            'error' => $error,
        ];
    }
}

if (!function_exists('rwthJobsCanonicalJobUrl')) {
    function rwthJobsCanonicalJobUrl(int $sourceId): string
    {
        return 'https://jobs.rwth-aachen.de/index.php?ac=jobad&id=' . $sourceId . '&language=1';
    }
}

if (!function_exists('rwthJobsSourceIdFromUrl')) {
    function rwthJobsSourceIdFromUrl(string $url): ?int
    {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query)) {
            return null;
        }
        parse_str($query, $params);
        $id = isset($params['id']) ? (int)$params['id'] : 0;
        return $id > 0 ? $id : null;
    }
}

if (!function_exists('rwthJobsNormalizeJobCode')) {
    function rwthJobsNormalizeJobCode(string $raw): ?string
    {
        if (!preg_match('/\bV\s*0*(\d{4,12})\b/iu', rwthJobsCleanText($raw), $m)) {
            return null;
        }
        return 'V' . str_pad((string)((int)$m[1]), 9, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('rwthJobsJobCodeFromUrl')) {
    function rwthJobsJobCodeFromUrl(string $url): ?string
    {
        $decoded = rawurldecode(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (preg_match('/\b(V\d{6,12})\b/iu', $decoded, $m)) {
            return rwthJobsNormalizeJobCode($m[1]);
        }
        return null;
    }
}

if (!function_exists('rwthJobsCmsSourceId')) {
    function rwthJobsCmsSourceId(string $jobCode): int
    {
        $code = rwthJobsNormalizeJobCode($jobCode);
        if ($code === null) {
            throw new InvalidArgumentException('Ungültige RWTH-Jobnummer: ' . $jobCode);
        }
        $number = (int)substr($code, 1);
        // Das vorhandene DB-Schema verlangt eine numerische source_id. Der CMS-Bereich
        // wird bewusst weit von den alten BeeSite-IDs getrennt, ohne eine Migration zu erzwingen.
        return 2000000000 + $number;
    }
}

if (!function_exists('rwthJobsIsRwthCmsHost')) {
    function rwthJobsIsRwthCmsHost(string $host): bool
    {
        $host = strtolower(trim($host));
        return $host === 'rwth-aachen.de' || str_ends_with($host, '.rwth-aachen.de');
    }
}

if (!function_exists('rwthJobsIsPortalHost')) {
    function rwthJobsIsPortalHost(string $host): bool
    {
        $host = strtolower(trim($host));
        return in_array($host, [
            'jobs.rwth-aachen.de',
            'www.jobs.rwth-aachen.de',
            'beesite-f.zhv.rwth-aachen.de',
        ], true);
    }
}

if (!function_exists('rwthJobsCmsIsPaginationLink')) {
    function rwthJobsCmsIsPaginationLink(string $url, string $text): bool
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host !== 'www.rwth-aachen.de' && $host !== 'rwth-aachen.de') {
            return false;
        }

        $path = rwthJobsLower((string)parse_url($url, PHP_URL_PATH));
        if (!str_contains($path, '~buym') || !str_contains($path, 'rwth-jobportal')) {
            return false;
        }

        $query = (string)parse_url($url, PHP_URL_QUERY);
        parse_str($query, $params);

        if (isset($params['page']) || isset($params['showall'])) {
            return true;
        }

        $text = rwthJobsLower($text);
        return preg_match('/^\d+\s*[-–]\s*\d+$/u', $text) === 1
            || preg_match('/^\d+$/', $text) === 1
            || str_contains($text, 'nächste')
            || str_contains($text, 'weiter')
            || str_contains($text, 'letzte seite')
            || str_contains($text, 'alle einträge')
            || str_contains($text, 'all entries');
    }
}

if (!function_exists('rwthJobsCmsEmploymentFilterUrl')) {
    function rwthJobsCmsEmploymentFilterUrl(string $html, string $baseUrl): ?string
    {
        try {
            $dom = rwthJobsDom($html);
            $xpath = new DOMXPath($dom);
            $wanted = 'einstellung im beschäftigtenverhältnis';
            $targetInput = null;

            // Variante 1: Text steht direkt im value.
            foreach ($xpath->query('//input[@name]') ?: [] as $input) {
                if (!($input instanceof DOMElement)) {
                    continue;
                }
                if (rwthJobsLower($input->getAttribute('value')) === $wanted) {
                    $targetInput = $input;
                    break;
                }
            }

            // Variante 2: Checkbox/Radio ist über ein label beschriftet.
            if (!$targetInput instanceof DOMElement) {
                foreach ($xpath->query('//label') ?: [] as $label) {
                    if (!($label instanceof DOMElement) || rwthJobsLower($label->textContent ?? '') !== $wanted) {
                        continue;
                    }
                    $candidate = $xpath->query('.//input[@name]', $label)->item(0);
                    if ($candidate instanceof DOMElement) {
                        $targetInput = $candidate;
                        break;
                    }
                    $for = trim($label->getAttribute('for'));
                    if ($for !== '') {
                        $candidate = $dom->getElementById($for);
                        if ($candidate instanceof DOMElement) {
                            $targetInput = $candidate;
                            break;
                        }
                    }
                }
            }

            if (!$targetInput instanceof DOMElement) {
                return null;
            }

            $name = trim($targetInput->getAttribute('name'));
            $value = $targetInput->getAttribute('value');
            if ($name === '' || $value === '') {
                return null;
            }

            $parts = parse_url($baseUrl);
            if (!$parts || empty($parts['host'])) {
                return null;
            }
            $query = [];
            if (!empty($parts['query'])) {
                parse_str($parts['query'], $query);
            }
            unset($query['page']);
            $query['showall'] = '1';
            // Bei Namen mit [] parse_str/http_build_query die Arrayform korrekt erzeugen.
            $cleanName = str_ends_with($name, '[]') ? substr($name, 0, -2) : $name;
            $query[$cleanName] = [$value];

            $scheme = $parts['scheme'] ?? 'https';
            $host = $parts['host'];
            $port = isset($parts['port']) ? ':' . $parts['port'] : '';
            $path = $parts['path'] ?? '/';
            return $scheme . '://' . $host . $port . $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('rwthJobsCmsRowMetadata')) {
    function rwthJobsCmsRowMetadata(DOMElement $anchor, string $jobCode): array
    {
        $title = rwthJobsCleanText($anchor->textContent ?? '');
        $title = preg_replace('/\s*\[' . preg_quote($jobCode, '/') . '\]\s*$/iu', '', $title) ?? $title;

        $institute = '';
        $publishedAt = null;
        $deadlineAt = null;

        $row = $anchor;
        while ($row instanceof DOMElement && strtolower($row->tagName) !== 'tr') {
            $parent = $row->parentNode;
            $row = $parent instanceof DOMElement ? $parent : null;
        }

        if ($row instanceof DOMElement) {
            $cells = [];
            foreach ($row->childNodes as $child) {
                if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['td', 'th'], true)) {
                    $cells[] = rwthJobsCleanText($child->textContent ?? '');
                }
            }

            if (isset($cells[0]) && preg_match('/veröffentlicht\s+am\s+(\d{1,2}\.\d{1,2}\.\d{4})/iu', $cells[0], $m)) {
                $publishedAt = rwthJobsParseGermanDate($m[1]);
            }
            if (isset($cells[1])) {
                $institute = preg_replace('/\s*\[\d+\]\s*$/u', '', $cells[1]) ?? $cells[1];
                $institute = rwthJobsCleanText($institute);
            }
            if (isset($cells[2])) {
                $deadlineAt = rwthJobsParseGermanDate($cells[2]);
            }
        }

        return [
            'job_id' => $jobCode,
            'title' => $title,
            'institute' => $institute,
            'published_at' => $publishedAt,
            'deadline_at' => $deadlineAt,
        ];
    }
}

if (!function_exists('rwthJobsCollectCmsLinks')) {
    function rwthJobsCollectCmsLinks(string $html, string $baseUrl): array
    {
        $jobs = [];
        $pages = [];

        try {
            $dom = rwthJobsDom($html);
            $xpath = new DOMXPath($dom);

            // Primär: komplette Ergebniszeile auswerten. So funktioniert die Erkennung
            // auch dann, wenn die V000...-Nummer nur als Text in der Zeile steht und
            // nicht Bestandteil des href ist.
            foreach ($xpath->query('//tr') ?: [] as $tr) {
                if (!($tr instanceof DOMElement)) {
                    continue;
                }
                $rowText = rwthJobsCleanText($tr->textContent ?? '');
                $jobCode = rwthJobsNormalizeJobCode($rowText);
                if ($jobCode === null) {
                    continue;
                }

                $chosen = null;
                foreach ($xpath->query('.//a[@href]', $tr) ?: [] as $a) {
                    if (!($a instanceof DOMElement)) {
                        continue;
                    }
                    $href = trim($a->getAttribute('href'));
                    if ($href === '' || str_starts_with(strtolower($href), 'mailto:')) {
                        continue;
                    }
                    $absolute = rwthJobsAbsoluteUrl($baseUrl, $href);
                    $host = strtolower((string)parse_url($absolute, PHP_URL_HOST));
                    if (!rwthJobsIsRwthCmsHost($host)) {
                        continue;
                    }
                    $path = rwthJobsLower((string)parse_url($absolute, PHP_URL_PATH));
                    // Bevorzugt die typische CMS-Datei; ansonsten reicht ein /go/id/-Link
                    // aus der Stellenzeile.
                    if (str_contains($path, '/file/') || str_contains($path, '/go/id/')) {
                        $chosen = $a;
                        if (rwthJobsJobCodeFromUrl($absolute) !== null) {
                            break;
                        }
                    }
                }

                if (!$chosen instanceof DOMElement) {
                    continue;
                }

                $absolute = rwthJobsAbsoluteUrl($baseUrl, $chosen->getAttribute('href'));
                $sourceId = rwthJobsCmsSourceId($jobCode);
                $meta = rwthJobsCmsRowMetadata($chosen, $jobCode);
                // Titel notfalls aus dem ersten Tabellenfeld statt nur aus dem Linktext.
                if (($meta['title'] ?? '') === '' || rwthJobsNormalizeJobCode((string)$meta['title']) !== null) {
                    $cells = [];
                    foreach ($tr->childNodes as $child) {
                        if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['td', 'th'], true)) {
                            $cells[] = rwthJobsCleanText($child->textContent ?? '');
                        }
                    }
                    if (isset($cells[0])) {
                        $candidate = preg_replace('/\s*\[' . preg_quote($jobCode, '/') . '\].*$/isu', '', $cells[0]) ?? $cells[0];
                        $candidate = preg_replace('/\s*\[veröffentlicht\s+am.*$/isu', '', $candidate) ?? $candidate;
                        $meta['title'] = rwthJobsCleanText($candidate);
                    }
                }
                $jobs[$sourceId] = array_merge($meta, [
                    'source_id' => $sourceId,
                    'job_id' => $jobCode,
                    'url' => $absolute,
                ]);
            }

            // Sekundär: Links direkt auswerten, falls das CMS irgendwann keine Tabelle mehr nutzt.
            foreach ($xpath->query('//a[@href]') ?: [] as $a) {
                if (!($a instanceof DOMElement)) {
                    continue;
                }
                $href = trim($a->getAttribute('href'));
                if ($href === '') {
                    continue;
                }
                $absolute = rwthJobsAbsoluteUrl($baseUrl, $href);
                $anchorText = rwthJobsCleanText($a->textContent ?? '');

                if (rwthJobsCmsIsPaginationLink($absolute, $anchorText)) {
                    $pages[$absolute] = true;
                }

                $host = strtolower((string)parse_url($absolute, PHP_URL_HOST));
                if (!rwthJobsIsRwthCmsHost($host)) {
                    continue;
                }
                $path = rwthJobsLower((string)parse_url($absolute, PHP_URL_PATH));
                if (!str_contains($path, '/file/') && !str_contains($path, '/go/id/')) {
                    continue;
                }

                $jobCode = rwthJobsJobCodeFromUrl($absolute) ?? rwthJobsNormalizeJobCode($anchorText);
                if ($jobCode === null) {
                    continue;
                }
                $sourceId = rwthJobsCmsSourceId($jobCode);
                if (!isset($jobs[$sourceId])) {
                    $meta = rwthJobsCmsRowMetadata($a, $jobCode);
                    $jobs[$sourceId] = array_merge($meta, [
                        'source_id' => $sourceId,
                        'job_id' => $jobCode,
                        'url' => $absolute,
                    ]);
                }
            }
        } catch (Throwable $e) {
            // Regex-Fallback darunter.
        }

        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match_all('/href\s*=\s*(["\'])(.*?)\1/isu', $decoded, $hrefMatches, PREG_SET_ORDER)) {
            foreach ($hrefMatches as $match) {
                $href = trim($match[2]);
                if ($href === '') {
                    continue;
                }
                $absolute = rwthJobsAbsoluteUrl($baseUrl, $href);
                $host = strtolower((string)parse_url($absolute, PHP_URL_HOST));
                if (!rwthJobsIsRwthCmsHost($host)) {
                    continue;
                }
                $path = rwthJobsLower((string)parse_url($absolute, PHP_URL_PATH));
                if (!str_contains($path, '/file/') && !str_contains($path, '/go/id/')) {
                    continue;
                }
                $jobCode = rwthJobsJobCodeFromUrl($absolute);
                if ($jobCode === null) {
                    continue;
                }
                $sourceId = rwthJobsCmsSourceId($jobCode);
                if (!isset($jobs[$sourceId])) {
                    $jobs[$sourceId] = [
                        'source_id' => $sourceId,
                        'job_id' => $jobCode,
                        'title' => '',
                        'institute' => '',
                        'published_at' => null,
                        'deadline_at' => null,
                        'url' => $absolute,
                    ];
                }
            }
        }

        ksort($jobs, SORT_NUMERIC);
        return ['jobs' => $jobs, 'pages' => array_keys($pages)];
    }
}

if (!function_exists('rwthJobsDiscoverFilteredJobUrls')) {
    function rwthJobsDiscoverFilteredJobUrls(): array
    {
        $startUrls = array_values(array_unique([
            RWTH_JOBS_CMS_URL,
            RWTH_JOBS_CMS_FALLBACK_URL,
        ]));
        $lastErrors = [];

        foreach ($startUrls as $startUrl) {
            $initial = rwthJobsHttpRequest($startUrl, 'GET', [], null, 25);
            if (!$initial['ok']) {
                $lastErrors[] = $startUrl . ': HTTP ' . $initial['status'] . ' ' . $initial['error'];
                continue;
            }

            // Das alte RWTH-CMS rendert die Filter serverseitig. Wir suchen das Feld
            // "Einstellung im Beschäftigtenverhältnis" dynamisch nach Label/Value,
            // statt den obfuskierten Parameternamen hart zu codieren.
            $filteredUrl = rwthJobsCmsEmploymentFilterUrl((string)$initial['body'], (string)$initial['url']);
            $filterApplied = false;
            $first = $initial;
            if ($filteredUrl !== null && $filteredUrl !== $initial['url']) {
                $filtered = rwthJobsHttpRequest($filteredUrl, 'GET', [], null, 25);
                if ($filtered['ok']) {
                    $probe = rwthJobsCollectCmsLinks((string)$filtered['body'], (string)$filtered['url']);
                    if (!empty($probe['jobs'])) {
                        $first = $filtered;
                        $filterApplied = true;
                    }
                }
            }

            $queue = [[$first['url'], $first['body']]];
            $queuedUrls = [$first['url'] => true];
            $visitedPages = [];
            $jobs = [];
            $pageCount = 0;
            $maxPages = 20;

            while ($queue && $pageCount < $maxPages) {
                [$pageUrl, $pageHtml] = array_shift($queue);
                if (isset($visitedPages[$pageUrl])) {
                    continue;
                }
                $visitedPages[$pageUrl] = true;
                $pageCount++;

                $links = rwthJobsCollectCmsLinks((string)$pageHtml, (string)$pageUrl);
                foreach ($links['jobs'] as $sourceId => $job) {
                    if (!isset($jobs[(int)$sourceId])) {
                        $jobs[(int)$sourceId] = $job;
                    } else {
                        foreach ($job as $key => $value) {
                            if (($jobs[(int)$sourceId][$key] ?? '') === '' || ($jobs[(int)$sourceId][$key] ?? null) === null) {
                                $jobs[(int)$sourceId][$key] = $value;
                            }
                        }
                    }
                }

                foreach ($links['pages'] as $nextPage) {
                    if (isset($queuedUrls[$nextPage]) || isset($visitedPages[$nextPage])) {
                        continue;
                    }
                    $queuedUrls[$nextPage] = true;
                    $next = rwthJobsHttpRequest($nextPage, 'GET', [], null, 25);
                    if ($next['ok']) {
                        $queue[] = [$next['url'], $next['body']];
                    }
                }
            }

            if (!$jobs) {
                $lastErrors[] = $startUrl . ': HTML geladen, aber keine RWTH-CMS-Stellenlinks/Ergebniszeilen mit V000... gefunden.';
                continue;
            }

            ksort($jobs, SORT_NUMERIC);
            $urls = [];
            foreach ($jobs as $sourceId => $job) {
                $urls[(int)$sourceId] = (string)$job['url'];
            }

            return [
                'urls' => $urls,
                'jobs' => $jobs,
                'portal_filter_applied' => $filterApplied,
                'result_pages' => $pageCount,
                'discovery_source' => 'rwth_cms_html',
                'discovery_url' => $first['url'],
            ];
        }

        throw new RuntimeException(
            'RWTH-CMS-Stellenliste konnte nicht ausgewertet werden. ' .
            ($lastErrors ? implode(' | ', $lastErrors) : 'Keine CMS-Stellenlinks gefunden.')
        );
    }
}

if (!function_exists('rwthJobsHttpMultiGet')) {
    function rwthJobsHttpMultiGet(array $urls, int $concurrency = 4): array
    {
        if (!function_exists('curl_multi_init')) {
            $out = [];
            foreach ($urls as $key => $url) {
                $out[$key] = rwthJobsHttpRequest((string)$url, 'GET', [], null, 20);
                usleep(100000);
            }
            return $out;
        }

        $results = [];
        $chunks = array_chunk($urls, max(1, $concurrency), true);

        foreach ($chunks as $chunk) {
            $mh = curl_multi_init();
            $handles = [];

            foreach ($chunk as $key => $url) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => (string)$url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 6,
                    CURLOPT_CONNECTTIMEOUT => 6,
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_USERAGENT => 'FIJI-RWTHJobs/1.0 (private personal job tracker)',
                    CURLOPT_HTTPHEADER => [
                        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Accept-Language: de-DE,de;q=0.9,en;q=0.6',
                    ],
                    CURLOPT_ENCODING => '',
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$key] = $ch;
            }

            do {
                $status = curl_multi_exec($mh, $running);
                if ($running > 0) {
                    curl_multi_select($mh, 1.0);
                }
            } while ($running > 0 && $status === CURLM_OK);

            foreach ($handles as $key => $ch) {
                $body = curl_multi_getcontent($ch);
                $error = curl_error($ch);
                $httpStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                $results[$key] = [
                    'ok' => $body !== false && $httpStatus >= 200 && $httpStatus < 400,
                    'status' => $httpStatus,
                    'body' => $body === false ? '' : (string)$body,
                    'url' => $finalUrl !== '' ? $finalUrl : (string)$chunk[$key],
                    'error' => $error,
                ];
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);

            // Kleine Pause zwischen Batches: öffentliche Seite nicht unnötig aggressiv abfragen.
            usleep(120000);
        }

        return $results;
    }
}

if (!function_exists('rwthJobsInnerHtml')) {
    function rwthJobsInnerHtml(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?? '';
        }
        return $html;
    }
}

if (!function_exists('rwthJobsSanitizeFragment')) {
    function rwthJobsSanitizeFragment(string $html, string $baseUrl): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = rwthJobsDom('<div id="rwth-root">' . $html . '</div>');
        $xpath = new DOMXPath($dom);
        $root = $xpath->query('//*[@id="rwth-root"]')->item(0);
        if (!$root instanceof DOMElement) {
            return '';
        }

        $allowed = ['p', 'ul', 'ol', 'li', 'strong', 'b', 'em', 'i', 'br', 'a', 'h3', 'h4'];
        $dangerous = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'svg', 'math'];
        $nodes = [];
        foreach ($xpath->query('.//*', $root) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $nodes[] = $node;
            }
        }

        for ($i = count($nodes) - 1; $i >= 0; $i--) {
            $node = $nodes[$i];
            $tag = strtolower($node->tagName);
            if (in_array($tag, $dangerous, true)) {
                $node->parentNode?->removeChild($node);
                continue;
            }
            if (!in_array($tag, $allowed, true)) {
                $parent = $node->parentNode;
                if ($parent) {
                    while ($node->firstChild) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);
                }
                continue;
            }

            $attrs = [];
            foreach ($node->attributes as $attr) {
                $attrs[] = $attr->name;
            }
            foreach ($attrs as $attrName) {
                $keep = ($tag === 'a' && in_array(strtolower($attrName), ['href', 'title'], true));
                if (!$keep) {
                    $node->removeAttribute($attrName);
                }
            }

            if ($tag === 'a') {
                $href = trim($node->getAttribute('href'));
                if ($href !== '') {
                    $href = rwthJobsAbsoluteUrl($baseUrl, $href);
                    $scheme = strtolower((string)parse_url($href, PHP_URL_SCHEME));
                    if (!in_array($scheme, ['http', 'https', 'mailto', 'tel'], true)) {
                        $node->removeAttribute('href');
                    } else {
                        $node->setAttribute('href', $href);
                        if (in_array($scheme, ['http', 'https'], true)) {
                            $node->setAttribute('target', '_blank');
                            $node->setAttribute('rel', 'noopener noreferrer');
                        }
                    }
                }
            }
        }

        return rwthJobsInnerHtml($root);
    }
}

if (!function_exists('rwthJobsHtmlToText')) {
    function rwthJobsHtmlToText(string $html): string
    {
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<\/(p|li|h[1-6]|div|ul|ol)>/i', "\n", $html) ?? $html;
        return rwthJobsCleanText(strip_tags($html));
    }
}

if (!function_exists('rwthJobsExtractSection')) {
    function rwthJobsExtractSection(DOMXPath $xpath, DOMDocument $dom, string $headingText, string $baseUrl): array
    {
        $target = rwthJobsLower($headingText);
        $heading = null;
        foreach ($xpath->query('//h2 | //h3 | //h4') ?: [] as $h) {
            if (!($h instanceof DOMElement)) {
                continue;
            }
            if (rwthJobsLower($h->textContent ?? '') === $target) {
                $heading = $h;
                break;
            }
        }

        if (!$heading instanceof DOMElement) {
            return ['html' => '', 'text' => ''];
        }

        $parts = [];
        for ($node = $heading->nextSibling; $node !== null; $node = $node->nextSibling) {
            if ($node instanceof DOMElement && preg_match('/^h[1-4]$/i', $node->tagName)) {
                break;
            }
            if ($node instanceof DOMText && trim($node->textContent ?? '') === '') {
                continue;
            }
            $parts[] = $dom->saveHTML($node) ?: '';
        }

        $raw = trim(implode('', $parts));
        $sanitized = rwthJobsSanitizeFragment($raw, $baseUrl);
        return [
            'html' => $sanitized,
            'text' => rwthJobsHtmlToText($sanitized !== '' ? $sanitized : $raw),
        ];
    }
}

if (!function_exists('rwthJobsExtractSectionTextFallback')) {
    function rwthJobsExtractSectionTextFallback(string $fullText, string $heading, array $allHeadings): string
    {
        $fullText = rwthJobsCleanText($fullText);
        if ($fullText === '') {
            return '';
        }

        $nextHeadings = array_values(array_filter(
            $allHeadings,
            fn(string $candidate): bool => rwthJobsLower($candidate) !== rwthJobsLower($heading)
        ));
        $nextPattern = implode('|', array_map(fn(string $v): string => preg_quote($v, '/'), $nextHeadings));
        $headingPattern = preg_quote($heading, '/');

        $pattern = '/' . $headingPattern . '\s*(.*?)(?=' . ($nextPattern !== '' ? '(?:' . $nextPattern . ')|' : '') . '$)/isu';
        if (preg_match($pattern, $fullText, $m)) {
            return rwthJobsCleanText($m[1]);
        }
        return '';
    }
}

if (!function_exists('rwthJobsExtractMeta')) {
    function rwthJobsExtractMeta(DOMXPath $xpath): array
    {
        $meta = [];

        // BeeSite/klassische Listen: "Label: Wert".
        foreach ($xpath->query('//li') ?: [] as $li) {
            $text = rwthJobsCleanText($li->textContent ?? '');
            if (preg_match('/^([^:\n]{2,80})\s*:\s*(.+)$/us', $text, $m)) {
                $label = rwthJobsCleanText($m[1]);
                $value = rwthJobsCleanText($m[2]);
                if ($label !== '' && $value !== '' && !isset($meta[$label])) {
                    $meta[$label] = $value;
                }
            }
        }

        // Definitionslisten.
        foreach ($xpath->query('//dt') ?: [] as $dt) {
            if (!($dt instanceof DOMElement)) {
                continue;
            }
            $label = rtrim(rwthJobsCleanText($dt->textContent ?? ''), ':');
            $next = $dt->nextSibling;
            while ($next && !($next instanceof DOMElement)) {
                $next = $next->nextSibling;
            }
            if ($next instanceof DOMElement && strtolower($next->tagName) === 'dd') {
                $value = rwthJobsCleanText($next->textContent ?? '');
                if ($label !== '' && $value !== '' && !isset($meta[$label])) {
                    $meta[$label] = $value;
                }
            }
        }

        // RWTH-CMS-Detailseiten verwenden u. a. Tabellen in "Bewerbung".
        foreach ($xpath->query('//tr') ?: [] as $tr) {
            if (!($tr instanceof DOMElement)) {
                continue;
            }
            $cells = [];
            foreach ($tr->childNodes as $child) {
                if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['th', 'td'], true)) {
                    $cells[] = rwthJobsCleanText($child->textContent ?? '');
                }
            }
            if (count($cells) >= 2) {
                $label = rtrim($cells[0], ':');
                $value = $cells[1];
                if ($label !== '' && $value !== '' && !isset($meta[$label])) {
                    $meta[$label] = $value;
                }
            }
        }

        return $meta;
    }
}

if (!function_exists('rwthJobsMetaValue')) {
    function rwthJobsMetaValue(array $meta, string $wanted): string
    {
        $wantedNorm = rwthJobsLower(rtrim($wanted, ':'));
        foreach ($meta as $label => $value) {
            if (rwthJobsLower(rtrim((string)$label, ':')) === $wantedNorm) {
                return (string)$value;
            }
        }
        return '';
    }
}

if (!function_exists('rwthJobsNormalizeGrade')) {
    function rwthJobsNormalizeGrade(string $raw): string
    {
        $raw = rwthJobsCleanText($raw);
        if ($raw === '') {
            return '';
        }

        if (preg_match('/\bbis\s+zu\s+E(?:G)?\s*([0-9]{1,2}[a-z]?)/iu', $raw, $m)) {
            return 'bis zu EG ' . strtoupper($m[1]);
        }
        if (preg_match('/\bE(?:G)?\s*([0-9]{1,2}[a-z]?)/iu', $raw, $m)) {
            return 'EG ' . strtoupper($m[1]);
        }
        if (preg_match('/^\s*([0-9]{1,2}[a-z]?)\s*$/iu', $raw, $m)) {
            return 'EG ' . strtoupper($m[1]);
        }

        // Seltene Nicht-EG-Angaben nicht komplett verlieren.
        return function_exists('mb_substr') ? mb_substr($raw, 0, 100, 'UTF-8') : substr($raw, 0, 100);
    }
}

if (!function_exists('rwthJobsParseGermanDate')) {
    function rwthJobsParseGermanDate(string $raw): ?string
    {
        $raw = rwthJobsCleanText($raw);
        if (!preg_match('/\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b/', $raw, $m)) {
            return null;
        }
        $day = (int)$m[1];
        $month = (int)$m[2];
        $year = (int)$m[3];
        if (!checkdate($month, $day, $year)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}

if (!function_exists('rwthJobsFindInstitute')) {
    function rwthJobsFindInstitute(DOMXPath $xpath, string $title): string
    {
        // RWTH-CMS: Überschrift "Anbieter", danach der Name der Hochschuleinrichtung.
        foreach ($xpath->query('//h2 | //h3 | //h4') ?: [] as $h) {
            if (!($h instanceof DOMElement) || rwthJobsLower($h->textContent ?? '') !== 'anbieter') {
                continue;
            }
            for ($node = $h->nextSibling; $node !== null; $node = $node->nextSibling) {
                if ($node instanceof DOMElement && preg_match('/^h[1-4]$/i', $node->tagName)) {
                    break;
                }
                $text = rwthJobsCleanText($node->textContent ?? '');
                if ($text !== '') {
                    return preg_replace('/\s*\[\d+\]\s*$/u', '', $text) ?? $text;
                }
            }
        }

        // Legacy/BeeSite-Fallback.
        $sectionNames = array_map('rwthJobsLower', [
            'Anbieter', 'Unser Profil', 'Ihr Profil', 'Ihre Aufgaben', 'Unser Angebot',
            'Über uns', 'Bewerbung', 'Kontakt', 'Kontakt & Bewerbung',
        ]);
        foreach ($xpath->query('//h2 | //h3 | //h4') ?: [] as $h) {
            $text = rwthJobsCleanText($h->textContent ?? '');
            $norm = rwthJobsLower($text);
            if ($text !== '' && $norm !== rwthJobsLower($title) && !in_array($norm, $sectionNames, true)) {
                return $text;
            }
        }
        return '';
    }
}

if (!function_exists('rwthJobsExtractContactText')) {
    function rwthJobsExtractContactText(string $fullText): string
    {
        $fullText = rwthJobsCleanText($fullText);
        if ($fullText === '') {
            return '';
        }

        // Im aktuellen RWTH-Markup ist "Kontakt & Bewerbung" nicht zwingend eine h2/h3-Überschrift.
        // Deshalb wird dieser Block zusätzlich aus dem Seitentext geschnitten.
        if (preg_match(
            '/Kontakt\s*&\s*Bewerbung\s*(.*?)(?=\s*(?:A\s*:\s*Share|Share\s+with|B\s*:\s*Share|Page\s+footer|Fußzeile)\b|$)/isu',
            $fullText,
            $m
        )) {
            return rwthJobsCleanText($m[1]);
        }

        // Fallback ab erster Kontaktpersonen-Markierung; bis typische Share-/Footer-Texte.
        if (preg_match(
            '/((?:Kontaktperson|Weitere\s+Kontaktperson).*?)(?=\s*(?:A\s*:\s*Share|Share\s+with|B\s*:\s*Share|Page\s+footer|Fußzeile)\b|$)/isu',
            $fullText,
            $m
        )) {
            return rwthJobsCleanText($m[1]);
        }

        return '';
    }
}

if (!function_exists('rwthJobsParseContacts')) {
    function rwthJobsParseContacts(string $text): array
    {
        $text = rwthJobsCleanText($text);
        if ($text === '') {
            return [];
        }

        $lines = preg_split('/\R+/u', $text) ?: [];
        $lines = array_values(array_filter(array_map('rwthJobsCleanText', $lines), fn(string $v): bool => $v !== ''));
        if (!$lines) {
            return [];
        }

        $markerIdx = [];
        foreach ($lines as $i => $line) {
            $lower = rwthJobsLower($line);
            if (str_contains($lower, 'kontaktperson') || str_contains($lower, 'weitere kontaktperson')) {
                $markerIdx[] = $i;
            }
        }

        $blocks = [];
        if ($markerIdx) {
            foreach ($markerIdx as $n => $start) {
                $end = $markerIdx[$n + 1] ?? count($lines);
                $blocks[] = array_slice($lines, $start, $end - $start);
            }
        } else {
            $blocks[] = $lines;
        }

        $contacts = [];
        foreach ($blocks as $block) {
            if (!$block) {
                continue;
            }

            $email = '';
            $phone = '';
            $name = '';
            $rest = [];

            foreach ($block as $idx => $line) {
                if (preg_match('/(?:E-?Mail|Email)\s*:\s*([^\s<>]+@[^\s<>]+)/iu', $line, $m)) {
                    $email = trim($m[1], " \t\n\r\0\x0B.,;<>[](){}");
                    continue;
                }
                if (preg_match('/(?:^|\b)(?:Work\s+)?(?:Tel\.?|Telefon)\s*:\s*(.+)$/iu', $line, $m)) {
                    $phone = rwthJobsCleanText($m[1]);
                    continue;
                }

                $lower = rwthJobsLower($line);
                if (str_contains($lower, 'kontaktperson') || str_contains($lower, 'rückfragen zur bewerbung')) {
                    continue;
                }

                if ($name === '' && !preg_match('/\b\d{5}\b/u', $line) && !str_contains($line, '@')) {
                    $name = $line;
                } else {
                    $rest[] = $line;
                }
            }

            if ($email === '' && preg_match('/([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/iu', implode(' ', $block), $m)) {
                $email = $m[1];
            }

            if ($name !== '' || $email !== '' || $phone !== '' || $rest) {
                $contacts[] = [
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'details' => rwthJobsCleanText(implode("\n", $rest)),
                ];
            }
        }

        // Zusätzliche E-Mail-Adressen sicherstellen, falls die Blockerkennung sie übersehen hat.
        if (preg_match_all('/([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/iu', $text, $matches)) {
            $known = array_map(fn(array $c): string => rwthJobsLower((string)($c['email'] ?? '')), $contacts);
            foreach (array_unique($matches[1]) as $email) {
                if (!in_array(rwthJobsLower($email), $known, true)) {
                    $contacts[] = ['name' => '', 'email' => $email, 'phone' => '', 'details' => ''];
                }
            }
        }

        return $contacts;
    }
}

if (!function_exists('rwthJobsParseOfferDetails')) {
    function rwthJobsParseOfferDetails(string $offerText): array
    {
        $text = rwthJobsCleanText($offerText);
        $out = [
            'start_date' => '',
            'fixed_term' => '',
            'work_time' => '',
            'grade_raw' => '',
        ];

        if (preg_match('/Die\s+Stelle\s+ist\s+zum\s+(.+?)\s+zu\s+besetzen/iu', $text, $m)) {
            $start = rwthJobsCleanText($m[1]);
            $out['start_date'] = str_contains(rwthJobsLower($start), 'nächstmöglich') ? 'nächstmöglich' : $start;
        }

        if (preg_match('/\b(unbefristet)\b/iu', $text, $m)) {
            $out['fixed_term'] = 'Unbefristet';
        } elseif (preg_match('/\bbefristet\s+(auf|bis)\s+([^\.\n]+)/iu', $text, $m)) {
            $out['fixed_term'] = 'Befristet ' . rwthJobsCleanText($m[1] . ' ' . $m[2]);
        } elseif (preg_match('/\bbefristet\b/iu', $text)) {
            $out['fixed_term'] = 'Befristet';
        }

        $work = [];
        if (preg_match('/Es\s+handelt\s+sich\s+um\s+eine\s+(Vollzeit|Teilzeit)stelle/iu', $text, $m)) {
            $work[] = ucfirst(rwthJobsLower($m[1]));
        }
        if (preg_match('/Teilzeit\s+(?:ist|wäre)\s+möglich/iu', $text) || preg_match('/Vollzeit[^\.]*Teilzeit\s+möglich/iu', $text)) {
            $work[] = 'Teilzeit möglich';
        }
        if (preg_match('/regelmäßige\s+Wochenarbeitszeit\s+beträgt\s+([^\.\n]+)/iu', $text, $m)) {
            $work[] = rwthJobsCleanText($m[1]);
        }
        $out['work_time'] = implode(', ', array_values(array_unique(array_filter($work))));

        if (preg_match('/Die\s+Stelle\s+ist\s+bewertet\s+mit\s+([^\.\n]+)/iu', $text, $m)) {
            $out['grade_raw'] = rwthJobsCleanText($m[1]);
        }

        return $out;
    }
}

if (!function_exists('rwthJobsCollectMailtoContacts')) {
    function rwthJobsCollectMailtoContacts(DOMXPath $xpath): array
    {
        $contacts = [];
        foreach ($xpath->query('//a[@href]') ?: [] as $a) {
            if (!($a instanceof DOMElement)) {
                continue;
            }
            $href = html_entity_decode(trim($a->getAttribute('href')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!str_starts_with(strtolower($href), 'mailto:')) {
                continue;
            }
            $email = trim(preg_split('/[?;]/', substr($href, 7), 2)[0] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $contacts[rwthJobsLower($email)] = [
                'name' => '',
                'email' => $email,
                'phone' => '',
                'details' => '',
            ];
        }
        return array_values($contacts);
    }
}

if (!function_exists('rwthJobsParseJobPage')) {
    function rwthJobsParseJobPage(string $html, string $url, array $discovered = []): array
    {
        $dom = rwthJobsDom($html);
        $xpath = new DOMXPath($dom);

        $title = '';
        foreach ($xpath->query('//h1') ?: [] as $h1) {
            $candidate = rwthJobsCleanText($h1->textContent ?? '');
            if ($candidate !== '') {
                $title = $candidate;
                break;
            }
        }
        if ($title === '') {
            $title = rwthJobsCleanText((string)($discovered['title'] ?? ''));
        }
        if ($title === '') {
            $titleNode = $xpath->query('//title')->item(0);
            $title = rwthJobsCleanText($titleNode?->textContent ?? '');
            $title = preg_replace('/\s*\|\s*RWTH.*$/iu', '', $title) ?? $title;
        }

        $bodyNode = $xpath->query('//body')->item(0);
        $fullText = rwthJobsCleanText($bodyNode?->textContent ?? $dom->textContent ?? '');
        $meta = rwthJobsExtractMeta($xpath);

        $sectionNames = [
            'Anbieter', 'Unser Profil', 'Ihr Profil', 'Ihre Aufgaben', 'Unser Angebot',
            'Über uns', 'Bewerbung', 'Kontakt', 'Kontakt & Bewerbung',
        ];
        $sections = [];
        foreach ($sectionNames as $sectionName) {
            $sections[$sectionName] = rwthJobsExtractSection($xpath, $dom, $sectionName, $url);
            if (rwthJobsCleanText($sections[$sectionName]['text'] ?? '') === '') {
                $fallbackText = rwthJobsExtractSectionTextFallback($fullText, $sectionName, $sectionNames);
                if ($fallbackText !== '') {
                    $sections[$sectionName]['text'] = $fallbackText;
                }
            }
        }

        $employmentRelationship = preg_match(
            '/Die\s+Einstellung\s+erfolgt\s+im\s+Beschäftigtenverhältnis\.?/iu',
            $fullText
        ) === 1;

        $institute = rwthJobsCleanText($sections['Anbieter']['text'] ?? '');
        if ($institute !== '') {
            $institute = preg_split('/\R/u', $institute, 2)[0] ?? $institute;
            $institute = preg_replace('/\s*\[\d+\]\s*$/u', '', $institute) ?? $institute;
        }
        if ($institute === '') {
            $institute = rwthJobsCleanText((string)($discovered['institute'] ?? ''));
        }
        if ($institute === '') {
            $institute = rwthJobsFindInstitute($xpath, $title);
        }

        $jobId = rwthJobsNormalizeJobCode((string)($discovered['job_id'] ?? ''));
        if ($jobId === null) {
            $jobId = rwthJobsNormalizeJobCode(rwthJobsMetaValue($meta, 'Job-ID'));
        }
        if ($jobId === null) {
            $jobId = rwthJobsNormalizeJobCode(rwthJobsMetaValue($meta, 'Nummer'));
        }
        if ($jobId === null) {
            $jobId = rwthJobsJobCodeFromUrl($url);
        }
        if ($jobId === null && preg_match('/\bV\d{6,12}\b/iu', $fullText, $m)) {
            $jobId = rwthJobsNormalizeJobCode($m[0]);
        }
        if ($jobId === null) {
            throw new RuntimeException('Keine RWTH-Jobnummer V000... auf der CMS-Detailseite gefunden.');
        }

        $sourceId = isset($discovered['source_id']) ? (int)$discovered['source_id'] : rwthJobsCmsSourceId($jobId);
        if ($sourceId <= 0) {
            $sourceId = rwthJobsCmsSourceId($jobId);
        }

        $offerText = rwthJobsCleanText($sections['Unser Angebot']['text'] ?? '');
        $offer = rwthJobsParseOfferDetails($offerText !== '' ? $offerText : $fullText);

        $gradeRaw = rwthJobsMetaValue($meta, 'Stellenbewertung');
        if ($gradeRaw === '') {
            $gradeRaw = $offer['grade_raw'];
        }

        $publishedAt = rwthJobsParseGermanDate(rwthJobsMetaValue($meta, 'Veröffentlicht'));
        if ($publishedAt === null && !empty($discovered['published_at'])) {
            $publishedAt = (string)$discovered['published_at'];
        }

        $deadlineRaw = rwthJobsMetaValue($meta, 'Bewerbungsfrist');
        if ($deadlineRaw === '') {
            $deadlineRaw = rwthJobsMetaValue($meta, 'Frist');
        }
        $deadlineAt = rwthJobsParseGermanDate($deadlineRaw);
        if ($deadlineAt === null && !empty($discovered['deadline_at'])) {
            $deadlineAt = (string)$discovered['deadline_at'];
        }
        if ($deadlineAt === null && preg_match('/Bewerbungsfrist\s*:?\s*(\d{1,2}\.\d{1,2}\.\d{4})/iu', $fullText, $m)) {
            $deadlineAt = rwthJobsParseGermanDate($m[1]);
        }

        $startDate = rwthJobsMetaValue($meta, 'Startdatum');
        if ($startDate === '') {
            $startDate = $offer['start_date'];
        }
        $fixedTerm = rwthJobsMetaValue($meta, 'Befristung');
        if ($fixedTerm === '') {
            $fixedTerm = $offer['fixed_term'];
        }
        $workTime = rwthJobsMetaValue($meta, 'Arbeitszeit');
        if ($workTime === '') {
            $workTime = $offer['work_time'];
        }

        $contactParts = array_filter([
            rwthJobsCleanText($sections['Bewerbung']['text'] ?? ''),
            rwthJobsCleanText($sections['Kontakt']['text'] ?? ''),
            rwthJobsCleanText($sections['Kontakt & Bewerbung']['text'] ?? ''),
        ]);
        $contactText = rwthJobsCleanText(implode("\n\n", $contactParts));
        $contacts = rwthJobsParseContacts($contactText);

        // CMS-Mailto-Links ergänzen, damit "Jetzt bewerben" auch dann als direkter
        // Mail-Button verfügbar ist, wenn die Textanalyse keinen Kontaktblock erkennt.
        $mailtoContacts = rwthJobsCollectMailtoContacts($xpath);
        $knownEmails = [];
        foreach ($contacts as $contact) {
            if (($contact['email'] ?? '') !== '') {
                $knownEmails[rwthJobsLower((string)$contact['email'])] = true;
            }
        }
        foreach ($mailtoContacts as $contact) {
            $key = rwthJobsLower((string)$contact['email']);
            if (!isset($knownEmails[$key])) {
                $contacts[] = $contact;
                $knownEmails[$key] = true;
            }
        }

        $primary = ['name' => '', 'email' => '', 'phone' => ''];
        foreach ($contacts as $contact) {
            if (($contact['email'] ?? '') !== '') {
                $primary = $contact;
                break;
            }
        }
        if (($primary['email'] ?? '') === '' && $contacts) {
            $primary = $contacts[0];
        }

        $imageUrl = '';
        foreach ($xpath->query('//img[@src]') ?: [] as $img) {
            if (!($img instanceof DOMElement)) {
                continue;
            }
            $alt = rwthJobsCleanText($img->getAttribute('alt'));
            $src = trim($img->getAttribute('src'));
            if ($src === '') {
                continue;
            }
            if ($institute !== '' && $alt !== '' && str_contains(rwthJobsLower($alt), rwthJobsLower($institute))) {
                $imageUrl = rwthJobsAbsoluteUrl($url, $src);
                break;
            }
        }

        $data = [
            'meta' => $meta,
            'sections' => $sections,
            'contacts' => $contacts,
            'contact_text' => $contactText,
            'image_url' => $imageUrl,
            'full_text' => $fullText,
            'discovery' => $discovered,
        ];

        return [
            'source_id' => $sourceId,
            'job_id' => $jobId,
            'title' => $title,
            'institute' => $institute,
            'location' => rwthJobsMetaValue($meta, 'Einsatzort'),
            'fixed_term' => $fixedTerm,
            'grade' => rwthJobsNormalizeGrade($gradeRaw),
            'grade_raw' => $gradeRaw,
            'start_date' => $startDate,
            'work_time' => $workTime,
            'published_at' => $publishedAt,
            'deadline_at' => $deadlineAt,
            'job_type' => rwthJobsMetaValue($meta, 'Stellentyp'),
            'employment_relationship' => $employmentRelationship ? 'Beschäftigtenverhältnis' : '',
            'primary_contact_name' => (string)($primary['name'] ?? ''),
            'primary_contact_email' => (string)($primary['email'] ?? ''),
            'primary_contact_phone' => (string)($primary['phone'] ?? ''),
            'source_url' => $url,
            // Nur die tatsächlich ausschreibende Einrichtung entscheidet über die IT-Center-Markierung.
            // Der globale CMS-Seitentext enthält auf vielen Seiten Links/Navigation mit "IT Center"
            // und darf deshalb hier ausdrücklich NICHT berücksichtigt werden.
            'is_it_center' => preg_match('/^it\s*center(?:\s*[-–—:]|$)/u', rwthJobsLower($institute)) === 1 ? 1 : 0,
            'data_json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            'raw_html' => $html,
            'is_employment_relationship' => $employmentRelationship,
        ];
    }
}


if (!function_exists('rwthJobsIsBachelorIrrelevantResearchJob')) {
    /**
     * Filtert den typischen RWTH-Promotions-/Forschungsstellenfall:
     * Titel enthält "Wissenschaft..." UND "Mitarbeiter..." UND Bewertung enthält EG 13.
     *
     * Wichtig: EG 13 alleine wird NICHT gefiltert, damit z. B. IT-/Verwaltungsstellen
     * mit EG 13 weiterhin angezeigt werden.
     */
    function rwthJobsIsBachelorIrrelevantResearchJob(array $job): bool
    {
        $title = rwthJobsLower((string)($job['title'] ?? ''));
        $grade = rwthJobsLower((string)($job['grade'] ?? ''));

        $hasScientific = str_contains($title, 'wissenschaft');
        $hasEmployee = str_contains($title, 'mitarbeiter');
        $hasEg13 = preg_match('/\beg\s*13(?:\b|[a-z])/u', $grade) === 1;

        return $hasScientific && $hasEmployee && $hasEg13;
    }
}

if (!function_exists('rwthJobsDbPrepare')) {
    function rwthJobsDbPrepare(mysqli $db, string $sql): mysqli_stmt
    {
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('DB prepare fehlgeschlagen: ' . $db->error);
        }
        return $stmt;
    }
}

if (!function_exists('rwthJobsEnsureSyncRow')) {
    function rwthJobsEnsureSyncRow(mysqli $db): void
    {
        if (!$db->query('INSERT IGNORE INTO rwth_jobs_sync (id) VALUES (1)')) {
            throw new RuntimeException('rwth_jobs_sync ist nicht verfügbar: ' . $db->error);
        }
    }
}

if (!function_exists('rwthJobsGetNewCount')) {
    function rwthJobsGetNewCount(mysqli $db): int
    {
        $today = rwthJobsNowBerlin()->format('Y-m-d');
        $stmt = rwthJobsDbPrepare($db, "
            SELECT COUNT(*) AS c
            FROM rwth_jobs j
            LEFT JOIN rwth_jobs_sync s ON s.id = 1
            WHERE j.status = 'new'
              AND (j.deadline_at IS NULL OR j.deadline_at >= ?)
              AND (
                    s.last_success_at IS NULL
                    OR DATE(j.last_seen_at) = DATE(s.last_success_at)
                  )
        ");
        $stmt->bind_param('s', $today);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return max(0, (int)($row['c'] ?? 0));
    }
}

if (!function_exists('rwthJobsAcquireLock')) {
    function rwthJobsAcquireLock(mysqli $db): bool
    {
        $res = $db->query("SELECT GET_LOCK('fiji_rwth_jobs_sync', 0) AS l");
        if (!$res) {
            return false;
        }
        $row = $res->fetch_assoc();
        $res->free();
        return (int)($row['l'] ?? 0) === 1;
    }
}

if (!function_exists('rwthJobsReleaseLock')) {
    function rwthJobsReleaseLock(mysqli $db): void
    {
        @$db->query("SELECT RELEASE_LOCK('fiji_rwth_jobs_sync')");
    }
}

if (!function_exists('rwthJobsExistingSourceIds')) {
    function rwthJobsExistingSourceIds(mysqli $db): array
    {
        $out = [];
        $res = $db->query('SELECT source_id FROM rwth_jobs');
        if (!$res) {
            throw new RuntimeException('rwth_jobs konnte nicht gelesen werden: ' . $db->error);
        }
        while ($row = $res->fetch_assoc()) {
            $out[(int)$row['source_id']] = true;
        }
        $res->free();
        return $out;
    }
}

if (!function_exists('rwthJobsTouchExisting')) {
    function rwthJobsTouchExisting(mysqli $db, array $sourceIds, string $seenAt): void
    {
        if (!$sourceIds) {
            return;
        }
        $stmt = rwthJobsDbPrepare($db, 'UPDATE rwth_jobs SET last_seen_at = ?, source_url = ? WHERE source_id = ?');
        foreach ($sourceIds as $sourceId => $url) {
            $sid = (int)$sourceId;
            $u = (string)$url;
            $stmt->bind_param('ssi', $seenAt, $u, $sid);
            if (!$stmt->execute()) {
                throw new RuntimeException('Bestehende RWTH-Stelle konnte nicht aktualisiert werden: ' . $stmt->error);
            }
        }
        $stmt->close();
    }
}

if (!function_exists('rwthJobsUpsertJob')) {
    function rwthJobsUpsertJob(mysqli $db, array $job, string $seenAt): void
    {
        $sql = "
            INSERT INTO rwth_jobs (
                source_id, job_id, title, institute, location, fixed_term,
                grade, grade_raw, start_date, work_time, published_at, deadline_at,
                job_type, employment_relationship,
                primary_contact_name, primary_contact_email, primary_contact_phone,
                source_url, is_it_center, data_json, raw_html,
                first_seen_at, last_seen_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?
            )
            ON DUPLICATE KEY UPDATE
                job_id = VALUES(job_id),
                title = VALUES(title),
                institute = VALUES(institute),
                location = VALUES(location),
                fixed_term = VALUES(fixed_term),
                grade = VALUES(grade),
                grade_raw = VALUES(grade_raw),
                start_date = VALUES(start_date),
                work_time = VALUES(work_time),
                published_at = VALUES(published_at),
                deadline_at = VALUES(deadline_at),
                job_type = VALUES(job_type),
                employment_relationship = VALUES(employment_relationship),
                primary_contact_name = VALUES(primary_contact_name),
                primary_contact_email = VALUES(primary_contact_email),
                primary_contact_phone = VALUES(primary_contact_phone),
                source_url = VALUES(source_url),
                is_it_center = VALUES(is_it_center),
                data_json = VALUES(data_json),
                raw_html = VALUES(raw_html),
                last_seen_at = VALUES(last_seen_at)
        ";

        $stmt = rwthJobsDbPrepare($db, $sql);

        $sourceId = (int)$job['source_id'];
        $jobId = (string)$job['job_id'];
        $title = (string)$job['title'];
        $institute = (string)$job['institute'];
        $location = (string)$job['location'];
        $fixedTerm = (string)$job['fixed_term'];
        $grade = (string)$job['grade'];
        $gradeRaw = (string)$job['grade_raw'];
        $startDate = (string)$job['start_date'];
        $workTime = (string)$job['work_time'];
        $publishedAt = $job['published_at'] ?: null;
        $deadlineAt = $job['deadline_at'] ?: null;
        $jobType = (string)$job['job_type'];
        $employmentRelationship = (string)$job['employment_relationship'];
        $primaryContactName = (string)$job['primary_contact_name'];
        $primaryContactEmail = (string)$job['primary_contact_email'];
        $primaryContactPhone = (string)$job['primary_contact_phone'];
        $sourceUrl = (string)$job['source_url'];
        $isItCenter = (int)$job['is_it_center'];
        $dataJson = (string)$job['data_json'];
        $rawHtml = (string)$job['raw_html'];
        $firstSeenAt = $seenAt;
        $lastSeenAt = $seenAt;

        $stmt->bind_param(
            'isssssssssssssssssissss',
            $sourceId,
            $jobId,
            $title,
            $institute,
            $location,
            $fixedTerm,
            $grade,
            $gradeRaw,
            $startDate,
            $workTime,
            $publishedAt,
            $deadlineAt,
            $jobType,
            $employmentRelationship,
            $primaryContactName,
            $primaryContactEmail,
            $primaryContactPhone,
            $sourceUrl,
            $isItCenter,
            $dataJson,
            $rawHtml,
            $firstSeenAt,
            $lastSeenAt
        );

        if (!$stmt->execute()) {
            throw new RuntimeException('RWTH-Stelle konnte nicht gespeichert werden: ' . $stmt->error);
        }
        $stmt->close();
    }
}

if (!function_exists('rwthJobsSyncCore')) {
    function rwthJobsSyncCore(mysqli $db, string $mode): array
    {
        $db->set_charset('utf8mb4');
        $discovery = rwthJobsDiscoverFilteredJobUrls();
        $urls = $discovery['urls'];
        $discoveredJobs = $discovery['jobs'] ?? [];
        $existing = rwthJobsExistingSourceIds($db);
        $seenAt = rwthJobsNowBerlin()->format('Y-m-d H:i:s');

        $knownUrls = [];
        $fetchUrls = [];
        foreach ($urls as $sourceId => $url) {
            $sid = (int)$sourceId;

            // last_seen_at bedeutet: Stelle war in der aktuellen CMS-Stellenliste vorhanden.
            // Das wird unabhängig davon gesetzt, ob wir die Detailseite zusätzlich neu laden.
            if (isset($existing[$sid])) {
                $knownUrls[$sid] = $url;
            }

            if ($mode === 'manual' || !isset($existing[$sid])) {
                $fetchUrls[$sid] = $url;
            }
        }

        rwthJobsTouchExisting($db, $knownUrls, $seenAt);
        $responses = rwthJobsHttpMultiGet($fetchUrls, 4);

        $insertedOrUpdated = 0;
        $skippedRelationship = 0;
        $skippedMasterResearch = 0;
        $errors = [];
        $fetchedOk = 0;

        foreach ($responses as $sourceId => $response) {
            if (empty($response['ok'])) {
                $errors[] = 'ID ' . $sourceId . ': HTTP ' . ($response['status'] ?? 0) . ' ' . ($response['error'] ?? '');
                continue;
            }
            $fetchedOk++;

            try {
                $job = rwthJobsParseJobPage((string)$response['body'], (string)$response['url'], $discoveredJobs[(int)$sourceId] ?? []);
                if (empty($job['is_employment_relationship'])) {
                    $skippedRelationship++;
                    continue;
                }

                // Bachelor-Fokus: klassische wissenschaftliche EG-13-Mitarbeiterstellen
                // (typischerweise Master/vergleichbar bzw. Promotionsstellen) nicht speichern.
                if (rwthJobsIsBachelorIrrelevantResearchJob($job)) {
                    $skippedMasterResearch++;
                    continue;
                }

                rwthJobsUpsertJob($db, $job, $seenAt);
                $insertedOrUpdated++;
            } catch (Throwable $e) {
                $errors[] = 'ID ' . $sourceId . ': ' . $e->getMessage();
            }
        }

        return [
            'ok' => true,
            'mode' => $mode,
            'discovered' => count($urls),
            'result_pages' => (int)$discovery['result_pages'],
            'portal_filter_applied' => (bool)$discovery['portal_filter_applied'],
            'details_requested' => count($fetchUrls),
            'details_ok' => $fetchedOk,
            'saved' => $insertedOrUpdated,
            'known_touched' => count($knownUrls),
            'skipped_not_employment_relationship' => $skippedRelationship,
            'skipped_scientific_staff_eg13' => $skippedMasterResearch,
            'errors' => $errors,
            'new_count' => rwthJobsGetNewCount($db),
        ];
    }
}

if (!function_exists('rwthJobsUpdateSyncMeta')) {
    function rwthJobsUpdateSyncMeta(mysqli $db, array $stats, ?string $error = null): void
    {
        rwthJobsEnsureSyncRow($db);
        $now = rwthJobsNowBerlin()->format('Y-m-d H:i:s');
        if ($error === null && !empty($stats['errors']) && is_array($stats['errors'])) {
            $firstError = (string)($stats['errors'][0] ?? '');
            $error = count($stats['errors']) . ' Detailfehler' . ($firstError !== '' ? ': ' . $firstError : '');
        }
        $json = json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $stmt = rwthJobsDbPrepare($db, '
            UPDATE rwth_jobs_sync
            SET last_success_at = ?, last_error = ?, last_stats_json = ?
            WHERE id = 1
        ');
        $stmt->bind_param('sss', $now, $error, $json);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('rwthJobsMaybeDailyFetch')) {
    function rwthJobsMaybeDailyFetch(mysqli $db): array
    {
        $db->set_charset('utf8mb4');
        rwthJobsEnsureSyncRow($db);
        $today = rwthJobsNowBerlin()->format('Y-m-d');

        $stmt = rwthJobsDbPrepare($db, 'SELECT last_auto_attempt_at FROM rwth_jobs_sync WHERE id = 1');
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!empty($row['last_auto_attempt_at']) && substr((string)$row['last_auto_attempt_at'], 0, 10) === $today) {
            return ['ok' => true, 'skipped' => 'already_attempted_today'];
        }

        if (!rwthJobsAcquireLock($db)) {
            return ['ok' => true, 'skipped' => 'sync_already_running'];
        }

        try {
            // Unter Lock nochmals prüfen, damit parallele Requests wirklich nur einen Tageslauf starten.
            $stmt = rwthJobsDbPrepare($db, 'SELECT last_auto_attempt_at FROM rwth_jobs_sync WHERE id = 1');
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!empty($row['last_auto_attempt_at']) && substr((string)$row['last_auto_attempt_at'], 0, 10) === $today) {
                return ['ok' => true, 'skipped' => 'already_attempted_today'];
            }

            $attemptAt = rwthJobsNowBerlin()->format('Y-m-d H:i:s');
            $stmt = rwthJobsDbPrepare($db, 'UPDATE rwth_jobs_sync SET last_auto_attempt_at = ? WHERE id = 1');
            $stmt->bind_param('s', $attemptAt);
            $stmt->execute();
            $stmt->close();

            try {
                $stats = rwthJobsSyncCore($db, 'auto');
                rwthJobsUpdateSyncMeta($db, $stats, null);
                return $stats;
            } catch (Throwable $e) {
                $error = $e->getMessage();
                $emptyStats = ['ok' => false, 'mode' => 'auto', 'error' => $error];
                $json = json_encode($emptyStats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $stmt = rwthJobsDbPrepare($db, 'UPDATE rwth_jobs_sync SET last_error = ?, last_stats_json = ? WHERE id = 1');
                $stmt->bind_param('ss', $error, $json);
                $stmt->execute();
                $stmt->close();
                return $emptyStats;
            }
        } finally {
            rwthJobsReleaseLock($db);
        }
    }
}

if (!function_exists('rwthJobsManualFetch')) {
    function rwthJobsManualFetch(mysqli $db): array
    {
        $db->set_charset('utf8mb4');
        rwthJobsEnsureSyncRow($db);

        if (!rwthJobsAcquireLock($db)) {
            return ['ok' => false, 'error' => 'Ein RWTH-Fetch läuft bereits.'];
        }

        try {
            $attemptAt = rwthJobsNowBerlin()->format('Y-m-d H:i:s');
            $stmt = rwthJobsDbPrepare($db, 'UPDATE rwth_jobs_sync SET last_manual_attempt_at = ? WHERE id = 1');
            $stmt->bind_param('s', $attemptAt);
            $stmt->execute();
            $stmt->close();

            try {
                $stats = rwthJobsSyncCore($db, 'manual');
                rwthJobsUpdateSyncMeta($db, $stats, null);
                return $stats;
            } catch (Throwable $e) {
                $error = $e->getMessage();
                $stats = ['ok' => false, 'mode' => 'manual', 'error' => $error];
                $json = json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $stmt = rwthJobsDbPrepare($db, 'UPDATE rwth_jobs_sync SET last_error = ?, last_stats_json = ? WHERE id = 1');
                $stmt->bind_param('ss', $error, $json);
                $stmt->execute();
                $stmt->close();
                return $stats;
            }
        } finally {
            rwthJobsReleaseLock($db);
        }
    }
}

// Wenn navbar.php diese Datei nur als Funktionsbibliothek lädt: hier stoppen.
if (defined('RWTHJOBS_LIBRARY_ONLY') && RWTHJOBS_LIBRARY_ONLY) {
    return;
}

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
$checkconn->set_charset('utf8mb4');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['rwthjobs_csrf'])) {
    $_SESSION['rwthjobs_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['rwthjobs_csrf'];

function rwthJobsJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function rwthJobsRequireCsrf(string $expected): void
{
    $provided = (string)($_POST['csrf'] ?? '');
    if ($provided === '' || !hash_equals($expected, $provided)) {
        rwthJobsJson(['ok' => false, 'error' => 'Ungültiger CSRF-Token.'], 403);
    }
}

function rwthJobsFormatDate(?string $date): string
{
    if (!$date) {
        return '—';
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Europe/Berlin'));
    return $dt ? $dt->format('d.m.Y') : $date;
}


function rwthJobsIsVisibleInTable(mysqli $db, int $id): bool
{
    $today = rwthJobsNowBerlin()->format('Y-m-d');
    $stmt = rwthJobsDbPrepare($db, "
        SELECT
            CASE
                WHEN j.status = 'deleted' THEN 0
                WHEN j.status = 'applied' THEN 1
                WHEN (j.deadline_at IS NOT NULL AND j.deadline_at < ?) THEN 0
                WHEN (
                    s.last_success_at IS NOT NULL
                    AND DATE(j.last_seen_at) <> DATE(s.last_success_at)
                ) THEN 0
                ELSE 1
            END AS visible
        FROM rwth_jobs j
        LEFT JOIN rwth_jobs_sync s ON s.id = 1
        WHERE j.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('si', $today, $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return !empty($row['visible']);
}

// AJAX: manueller Komplett-Fetch.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['ajax'] ?? '') === 'sync') {
    rwthJobsRequireCsrf($csrfToken);
    try {
        rwthJobsJson(rwthJobsManualFetch($checkconn));
    } catch (Throwable $e) {
        rwthJobsJson(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// AJAX: Status ändern (new / interesting / applied / deleted).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['ajax'] ?? '') === 'status') {
    rwthJobsRequireCsrf($csrfToken);
    $id = (int)($_POST['id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    $allowed = ['new', 'interesting', 'applied', 'deleted'];

    if ($id <= 0 || !in_array($status, $allowed, true)) {
        rwthJobsJson(['ok' => false, 'error' => 'Ungültige Statusänderung.'], 400);
    }

    $now = rwthJobsNowBerlin()->format('Y-m-d H:i:s');
    $stmt = rwthJobsDbPrepare($checkconn, 'UPDATE rwth_jobs SET status = ?, status_updated_at = ? WHERE id = ?');
    $stmt->bind_param('ssi', $status, $now, $id);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        rwthJobsJson(['ok' => false, 'error' => $error], 500);
    }
    $affected = $stmt->affected_rows;
    $stmt->close();

    rwthJobsJson([
        'ok' => true,
        'id' => $id,
        'status' => $status,
        'affected' => $affected,
        'new_count' => rwthJobsGetNewCount($checkconn),
        'visible' => rwthJobsIsVisibleInTable($checkconn, $id),
    ]);
}

// AJAX: Detaildaten für Modal.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['ajax'] ?? '') === 'job') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        rwthJobsJson(['ok' => false, 'error' => 'Ungültige Job-ID.'], 400);
    }

    $stmt = rwthJobsDbPrepare($checkconn, '
        SELECT
            id, source_id, job_id, title, institute, location, fixed_term,
            grade, grade_raw, start_date, work_time, published_at, deadline_at,
            job_type, employment_relationship,
            primary_contact_name, primary_contact_email, primary_contact_phone,
            source_url, is_it_center, status, data_json, first_seen_at, last_seen_at
        FROM rwth_jobs
        WHERE id = ?
        LIMIT 1
    ');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        rwthJobsJson(['ok' => false, 'error' => 'Stelle nicht gefunden.'], 404);
    }

    $data = json_decode((string)($row['data_json'] ?? ''), true);
    if (!is_array($data)) {
        $data = [];
    }
    unset($row['data_json']);
    $row['data'] = $data;
    $row['published_display'] = rwthJobsFormatDate($row['published_at'] ?? null);
    $row['deadline_display'] = rwthJobsFormatDate($row['deadline_at'] ?? null);

    rwthJobsJson(['ok' => true, 'job' => $row]);
}

// Normale Seitenansicht. Automatische Aktualisierung läuft extern per Cron; hier nur manuell per Button.
$jobs = [];
$queryError = null;
$todayForVisibility = rwthJobsNowBerlin()->format('Y-m-d');
$stmtJobs = $checkconn->prepare("
    SELECT
        j.id, j.job_id, j.title, j.institute, j.grade,
        j.published_at, j.deadline_at, j.start_date,
        j.status, j.is_it_center
    FROM rwth_jobs j
    LEFT JOIN rwth_jobs_sync s ON s.id = 1
    WHERE j.status <> 'deleted'
      AND (
            j.status = 'applied'
            OR (
                (j.deadline_at IS NULL OR j.deadline_at >= ?)
                AND (
                    s.last_success_at IS NULL
                    OR DATE(j.last_seen_at) = DATE(s.last_success_at)
                )
            )
          )
    ORDER BY
        CASE j.status
            WHEN 'new' THEN 0
            WHEN 'interesting' THEN 1
            WHEN 'applied' THEN 2
            ELSE 3
        END,
        j.published_at DESC,
        j.id DESC
");
if ($stmtJobs) {
    $stmtJobs->bind_param('s', $todayForVisibility);
    if ($stmtJobs->execute()) {
        $res = $stmtJobs->get_result();
        while ($row = $res->fetch_assoc()) {
            $jobs[] = $row;
        }
        $res->free();
    } else {
        $queryError = $stmtJobs->error;
    }
    $stmtJobs->close();
} else {
    $queryError = $checkconn->error;
}

$statusCounts = ['new' => 0, 'interesting' => 0, 'applied' => 0];
foreach ($jobs as $job) {
    $status = (string)$job['status'];
    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }
}

$syncInfo = null;
try {
    rwthJobsEnsureSyncRow($checkconn);
    $resSync = $checkconn->query('SELECT last_auto_attempt_at, last_manual_attempt_at, last_success_at, last_error, last_stats_json FROM rwth_jobs_sync WHERE id = 1');
    if ($resSync) {
        $syncInfo = $resSync->fetch_assoc();
        $resSync->free();
    }
} catch (Throwable $e) {
    $queryError = $queryError ?: $e->getMessage();
}

$page_title = 'RWTH Jobs';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>

<div id="rwthJobsPage" class="lt-page rwth-jobs-page">
    <div class="toolbar rwth-jobs-toolbar">
        <div>
            <h1 class="ueberschrift rwth-jobs-title">RWTH Jobs</h1>
            <div class="subtle">
                <?php if (!empty($syncInfo['last_success_at'])): ?>
                    Letzter erfolgreicher Fetch: <?= htmlspecialchars((string)$syncInfo['last_success_at'], ENT_QUOTES, 'UTF-8') ?>
                <?php else: ?>
                    Noch kein erfolgreicher Fetch.
                <?php endif; ?>
            </div>
        </div>

        <div class="rwth-jobs-toolbar-actions">
            <div class="rwth-jobs-summary" aria-label="Stellenstatus">
                <span class="rwth-jobs-summary-item rwth-jobs-summary-item--new">
                    <span class="rwth-jobs-summary-label">Neu</span>
                    <strong class="rwth-jobs-summary-value" id="rwthNewCountBadge"><?= (int)$statusCounts['new'] ?></strong>
                </span>
                <span class="rwth-jobs-summary-item">
                    <span class="rwth-jobs-summary-label">Interessant</span>
                    <strong class="rwth-jobs-summary-value" id="rwthInterestingCountBadge"><?= (int)$statusCounts['interesting'] ?></strong>
                </span>
                <span class="rwth-jobs-summary-item">
                    <span class="rwth-jobs-summary-label">Beworben</span>
                    <strong class="rwth-jobs-summary-value" id="rwthAppliedCountBadge"><?= (int)$statusCounts['applied'] ?></strong>
                </span>
            </div>
            <button type="button" id="rwthSyncButton">Jetzt aktualisieren</button>
        </div>
    </div>

    <?php if ($queryError): ?>
        <div class="rwth-jobs-fetch-error">
            DB-Fehler: <?= htmlspecialchars($queryError, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($syncInfo['last_error'])): ?>
        <div class="rwth-jobs-fetch-error">
            Letzter Fetch-Fehler: <?= htmlspecialchars((string)$syncInfo['last_error'], ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="rwth-jobs-table-wrap">
        <table class="food-table rwth-jobs-table" id="rwthJobsTable">
            <thead>
                <tr>
                    <th>Jobtitel</th>
                    <th>Institut</th>
                    <th>Stellenbewertung</th>
                    <th>Veröffentlicht</th>
                    <th>Bewerbungsfrist</th>
                    <th>Startdatum</th>
                    <th class="rwth-job-actions-col">Aktionen</th>
                </tr>
            </thead>
            <tbody id="rwthJobsBody">
                <?php if (!$jobs): ?>
                    <tr id="rwthEmptyRow">
                        <td colspan="7" class="rwth-jobs-empty">Noch keine Stellen gespeichert. Nutze „Jetzt aktualisieren“.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($jobs as $job): ?>
                        <?php
                            $status = (string)$job['status'];
                            $rowClasses = ['rwth-job-row', 'rwth-job-row--' . $status];
                            // Für die Tabellenmarkierung ausschließlich das Institut auswerten.
                            // So wirken alte, durch den früheren Parser fälschlich gesetzte DB-Flags nicht weiter.
                            $instituteNorm = rwthJobsLower((string)$job['institute']);
                            $isItCenterRow = preg_match('/^it\s*center(?:\s*[-–—:]|$)/u', $instituteNorm) === 1;
                            if ($isItCenterRow) {
                                $rowClasses[] = 'rwth-job-row--itc';
                            }

                            $stateButtonLabel = match ($status) {
                                'interesting' => 'Beworben',
                                'applied' => 'Interessant',
                                default => 'Interessant',
                            };
                            $stateButtonTarget = match ($status) {
                                'interesting' => 'applied',
                                'applied' => 'interesting',
                                default => 'interesting',
                            };
                            $statusLabel = match ($status) {
                                'applied' => 'Beworben',
                                default => '',
                            };
                        ?>
                        <tr
                            class="<?= htmlspecialchars(implode(' ', $rowClasses), ENT_QUOTES, 'UTF-8') ?>"
                            data-job-id="<?= (int)$job['id'] ?>"
                            data-status="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"
                            tabindex="0"
                        >
                            <td>
                                <div class="rwth-job-title-cell">
                                    <strong><?= htmlspecialchars((string)$job['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <?php if ($statusLabel !== ''): ?>
                                        <span class="rwth-job-status-badge"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($job['job_id'])): ?>
                                        <span class="subtle"><?= htmlspecialchars((string)$job['job_id'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?= htmlspecialchars((string)$job['institute'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($job['grade'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(rwthJobsFormatDate($job['published_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(rwthJobsFormatDate($job['deadline_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($job['start_date'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="rwth-job-actions-cell">
                                <div class="rwth-job-actions">
                                    <button
                                        type="button"
                                        class="rwth-job-state-btn"
                                        data-action="status"
                                        data-target-status="<?= htmlspecialchars($stateButtonTarget, ENT_QUOTES, 'UTF-8') ?>"
                                    ><?= htmlspecialchars($stateButtonLabel, ENT_QUOTES, 'UTF-8') ?></button>
                                    <button
                                        type="button"
                                        class="lt-delete-btn"
                                        data-action="delete"
                                    >Löschen</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="rwthJobModal" class="modal rwth-job-modal hidden" aria-hidden="true">
    <div class="modal-content rwth-job-modal-content" role="dialog" aria-modal="true" aria-labelledby="rwthJobModalTitle">
        <span class="close-button" id="rwthJobModalClose" role="button" tabindex="0" aria-label="Schließen">&times;</span>
        <div id="rwthJobModalBody">
            <h2 id="rwthJobModalTitle" class="rwth-job-modal-title">Stelle wird geladen…</h2>
        </div>
    </div>
</div>

<div id="rwthJobsToast" class="status-msg hidden"></div>

<script>
(() => {
  'use strict';

  const csrf = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const syncButton = document.getElementById('rwthSyncButton');
  const tableBody = document.getElementById('rwthJobsBody');
  const modal = document.getElementById('rwthJobModal');
  const modalBody = document.getElementById('rwthJobModalBody');
  const modalClose = document.getElementById('rwthJobModalClose');
  const toast = document.getElementById('rwthJobsToast');
  const newCountBadge = document.getElementById('rwthNewCountBadge');
  const interestingCountBadge = document.getElementById('rwthInterestingCountBadge');
  const appliedCountBadge = document.getElementById('rwthAppliedCountBadge');
  let toastTimer = null;

  function escapeHtml(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function showToast(message, isError = false) {
    if (!toast) return;
    clearTimeout(toastTimer);
    toast.textContent = message;
    toast.classList.remove('hidden');
    toast.classList.toggle('rwth-jobs-toast--error', !!isError);
    toastTimer = setTimeout(() => toast.classList.add('hidden'), 5000);
  }

  async function postAjax(action, data = {}) {
    const body = new URLSearchParams({ csrf, ...data });
    const response = await fetch(`/check/rwthjobs?ajax=${encodeURIComponent(action)}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body,
      cache: 'no-store'
    });
    const json = await response.json().catch(() => ({ ok: false, error: 'Ungültige Serverantwort.' }));
    if (!response.ok || !json.ok) {
      throw new Error(json.error || `HTTP ${response.status}`);
    }
    return json;
  }

  function statusSortValue(status) {
    if (status === 'interesting') return 0;
    if (status === 'new') return 1;
    if (status === 'applied') return 2;
    return 3;
  }

  function reorderRows() {
    if (!tableBody) return;
    const rows = [...tableBody.querySelectorAll('tr.rwth-job-row')];
    rows.sort((a, b) => {
      const sa = statusSortValue(a.dataset.status || 'new');
      const sb = statusSortValue(b.dataset.status || 'new');
      if (sa !== sb) return sa - sb;
      return Number(b.dataset.jobId || 0) - Number(a.dataset.jobId || 0);
    });
    rows.forEach(row => tableBody.appendChild(row));
  }

  function updateNewCount(count) {
    if (newCountBadge) newCountBadge.textContent = String(Number(count) || 0);
    const n = Number(count) || 0;
    document.querySelectorAll('.js-rwth-jobs-badge').forEach(badge => {
      badge.textContent = String(Math.min(99, n));
      badge.classList.toggle('hidden', n <= 0);
    });
    document.querySelectorAll('.js-check-spotify-fallback').forEach(badge => {
      badge.classList.toggle('hidden', n > 0);
    });
  }

  function updateStatusSummary() {
    if (!tableBody) return;
    const rows = [...tableBody.querySelectorAll('tr.rwth-job-row')];
    const interesting = rows.filter(row => row.dataset.status === 'interesting').length;
    const applied = rows.filter(row => row.dataset.status === 'applied').length;
    if (interestingCountBadge) interestingCountBadge.textContent = String(interesting);
    if (appliedCountBadge) appliedCountBadge.textContent = String(applied);
  }

  function setRowStatus(row, status) {
    row.dataset.status = status;
    row.classList.remove('rwth-job-row--new', 'rwth-job-row--interesting', 'rwth-job-row--applied');
    row.classList.add(`rwth-job-row--${status}`);

    const stateButton = row.querySelector('[data-action="status"]');
    const titleCell = row.querySelector('.rwth-job-title-cell');
    titleCell?.querySelector('.rwth-job-status-badge')?.remove();

    if (status === 'interesting') {
      if (stateButton) {
        stateButton.textContent = 'Beworben';
        stateButton.dataset.targetStatus = 'applied';
      }
      // "Interessant" wird ausschließlich durch das Zeilen-Styling angezeigt.
    } else if (status === 'applied') {
      if (stateButton) {
        stateButton.textContent = 'Interessant';
        stateButton.dataset.targetStatus = 'interesting';
      }
      if (titleCell) {
        const badge = document.createElement('span');
        badge.className = 'rwth-job-status-badge';
        badge.textContent = 'Beworben';
        titleCell.querySelector('strong')?.insertAdjacentElement('afterend', badge);
      }
    } else {
      if (stateButton) {
        stateButton.textContent = 'Interessant';
        stateButton.dataset.targetStatus = 'interesting';
      }
    }
  }

  async function changeStatus(row, status) {
    const id = row?.dataset.jobId;
    if (!id) return;
    const result = await postAjax('status', { id, status });

    if (status === 'deleted') {
      row.remove();
      if (tableBody && !tableBody.querySelector('tr.rwth-job-row')) {
        tableBody.innerHTML = '<tr id="rwthEmptyRow"><td colspan="7" class="rwth-jobs-empty">Keine sichtbaren Stellen vorhanden.</td></tr>';
      }
      showToast('Stelle ausgeblendet.');
    } else {
      setRowStatus(row, status);

      if (result.visible === false) {
        row.remove();
        if (tableBody && !tableBody.querySelector('tr.rwth-job-row')) {
          tableBody.innerHTML = '<tr id="rwthEmptyRow"><td colspan="7" class="rwth-jobs-empty">Keine sichtbaren Stellen vorhanden.</td></tr>';
        }
        showToast('Stelle ist nicht mehr aktuell und wurde ausgeblendet.');
      } else {
        reorderRows();
        showToast(status === 'interesting' ? 'Als interessant markiert.' : 'Als beworben markiert.');
      }
    }
    updateNewCount(result.new_count);
    updateStatusSummary();
  }

  function safeSectionHtml(section) {
    if (!section || typeof section !== 'object') return '';
    if (typeof section.html === 'string' && section.html.trim() !== '') return section.html;
    if (typeof section.text === 'string' && section.text.trim() !== '') {
      return escapeHtml(section.text).replaceAll('\n', '<br>');
    }
    return '';
  }

  function renderMetaGrid(job) {
    const meta = job.data?.meta || {};
    const ordered = [
      ['Job-ID', job.job_id],
      ['Einsatzort', job.location],
      ['Befristung', job.fixed_term],
      ['Stellenbewertung', job.grade_raw || job.grade],
      ['Startdatum', job.start_date],
      ['Arbeitszeit', job.work_time],
      ['Veröffentlicht', job.published_display],
      ['Bewerbungsfrist', job.deadline_display],
      ['Stellentyp', job.job_type],
      ['Beschäftigungsart', job.employment_relationship]
    ];

    const known = new Set(ordered.map(([label]) => label.toLowerCase()));
    const cells = ordered
      .filter(([, value]) => value)
      .map(([label, value]) => `
        <div class="rwth-job-meta-item">
          <span class="subtle">${escapeHtml(label)}</span>
          <strong class="rwth-job-meta-value">${escapeHtml(value)}</strong>
        </div>
      `);

    for (const [label, value] of Object.entries(meta)) {
      const labelNorm = String(label).trim().toLowerCase();
      const isCmsNoise =
        labelNorm === 'postalisch' ||
        labelNorm === 'e-mail' ||
        labelNorm === 'email' ||
        (labelNorm.includes('daten') && labelNorm.includes('fakten') && labelNorm.includes('ranking'));

      if (!value || known.has(labelNorm) || isCmsNoise) continue;
      cells.push(`
        <div class="rwth-job-meta-item">
          <span class="subtle">${escapeHtml(label)}</span>
          <strong class="rwth-job-meta-value">${escapeHtml(value)}</strong>
        </div>
      `);
    }

    return `<div class="rwth-job-meta-grid">${cells.join('')}</div>`;
  }

  function initMetaCollapsibles() {
    if (!modalBody) return;

    requestAnimationFrame(() => {
      modalBody.querySelectorAll('.rwth-job-meta-item').forEach(item => {
        const value = item.querySelector('.rwth-job-meta-value');
        if (!value) return;

        item.classList.remove('rwth-job-meta-item--expandable', 'rwth-job-meta-item--expanded');
        item.removeAttribute('role');
        item.removeAttribute('tabindex');
        item.removeAttribute('aria-expanded');
        item.removeAttribute('title');

        // scrollHeight enthält trotz CSS-Line-Clamp die vollständige Texthöhe.
        if (value.scrollHeight <= value.clientHeight + 1) return;

        item.classList.add('rwth-job-meta-item--expandable');
        item.setAttribute('role', 'button');
        item.setAttribute('tabindex', '0');
        item.setAttribute('aria-expanded', 'false');
        item.setAttribute('title', 'Klicken zum Ausklappen');
      });
    });
  }

  function toggleMetaItem(item) {
    if (!item?.classList.contains('rwth-job-meta-item--expandable')) return;
    const expanded = item.classList.toggle('rwth-job-meta-item--expanded');
    item.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    item.setAttribute('title', expanded ? 'Klicken zum Einklappen' : 'Klicken zum Ausklappen');
  }

  function renderContacts(job) {
    const contacts = Array.isArray(job.data?.contacts) ? job.data.contacts : [];
    const contactHtml = safeSectionHtml(job.data?.sections?.['Kontakt']);

    const actionCards = contacts.map(contact => {
      const email = String(contact.email || '').trim();
      if (!email) return '';
      const subject = `Bewerbung: ${job.title || ''}${job.job_id ? ` (${job.job_id})` : ''}`;
      const mailto = `mailto:${email}?subject=${encodeURIComponent(subject)}`;
      return `
        <a class="rwth-job-mail-button" href="${escapeHtml(mailto)}">
          ${contact.name ? `Mail an ${escapeHtml(contact.name)}` : `Mail an ${escapeHtml(email)}`}
        </a>
      `;
    }).join('');

    // Die CMS-Sektion "Bewerbung" wird bewusst nicht ausgegeben: Nummer und Frist
    // stehen bereits oben; die dortigen Blöcke "Postalisch" und "E-Mail" sind redundant.
    // Fehlt eine eigene Kontakt-Sektion, bauen wir nur aus den strukturierten Kontaktdaten
    // eine saubere Fallback-Anzeige statt den gesamten CMS-Bewerbungsblock einzublenden.
    const structuredContacts = contacts.map(contact => {
      const name = String(contact.name || '').trim();
      const phone = String(contact.phone || '').trim();
      const detailLines = String(contact.details || '')
        .split('\n')
        .map(line => line.trim())
        .filter(line => {
          const lower = line.toLowerCase();
          return line &&
            !lower.startsWith('postalisch') &&
            !lower.startsWith('e-mail') &&
            !lower.startsWith('email') &&
            !(lower.includes('daten') && lower.includes('fakten') && lower.includes('ranking'));
        });
      const details = detailLines.map(line => escapeHtml(line)).join('<br>');

      if (!name && !phone && !details) return '';
      return `<div class="rwth-job-contact-card">
        ${name ? `<strong>${escapeHtml(name)}</strong>` : ''}
        ${phone ? `<span>${escapeHtml(phone)}</span>` : ''}
        ${details ? `<span>${details}</span>` : ''}
      </div>`;
    }).join('');

    if (!contactHtml && !structuredContacts && !actionCards) return '';

    const body = contactHtml
      ? `<div class="rwth-job-section-body">${contactHtml}</div>`
      : (structuredContacts ? `<div class="rwth-job-contact-list">${structuredContacts}</div>` : '');

    return `
      <section class="rwth-job-section">
        <h3>Kontakt</h3>
        ${body}
        ${actionCards ? `<div class="rwth-job-contact-actions">${actionCards}</div>` : ''}
      </section>
    `;
  }

  function renderJob(job) {
    const sections = job.data?.sections || {};
    const sectionOrder = ['Unser Profil', 'Ihr Profil', 'Ihre Aufgaben', 'Unser Angebot', 'Über uns'];
    const sectionHtml = sectionOrder.map(name => {
      const html = safeSectionHtml(sections[name]);
      if (!html) return '';
      return `<section class="rwth-job-section"><h3>${escapeHtml(name)}</h3><div class="rwth-job-section-body">${html}</div></section>`;
    }).join('');
    const sectionFallback = sectionHtml
      ? ''
      : `<section class="rwth-job-section"><h3>Volltext</h3><div class="rwth-job-section-body rwth-job-fulltext">${escapeHtml(job.data?.full_text || '').replaceAll('\n', '<br>')}</div></section>`;

    return `
      <div class="rwth-job-modal-head">
        <div>
          <h2 id="rwthJobModalTitle" class="rwth-job-modal-title">${escapeHtml(job.title || 'RWTH-Stelle')}</h2>
          <div class="rwth-job-modal-institute">${escapeHtml(job.institute || '')}</div>
        </div>
      </div>

      ${renderMetaGrid(job)}
      ${sectionHtml}
      ${sectionFallback}
      ${renderContacts(job)}

      <div class="modal-actions rwth-job-modal-actions">
        ${job.source_url ? `<a class="rwth-job-source-button" href="${escapeHtml(job.source_url)}" target="_blank" rel="noopener noreferrer">Originalausschreibung</a>` : ''}
      </div>
    `;
  }

  async function openJobModal(id) {
    if (!modal || !modalBody) return;
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    modalBody.innerHTML = '<h2 id="rwthJobModalTitle" class="rwth-job-modal-title">Stelle wird geladen…</h2>';
    document.body.classList.add('rwth-jobs-modal-open');

    try {
      const response = await fetch(`/check/rwthjobs?ajax=job&id=${encodeURIComponent(id)}`, { cache: 'no-store' });
      const json = await response.json();
      if (!response.ok || !json.ok) throw new Error(json.error || `HTTP ${response.status}`);
      modalBody.innerHTML = renderJob(json.job);
      initMetaCollapsibles();
    } catch (error) {
      modalBody.innerHTML = `<h2 id="rwthJobModalTitle">Fehler</h2><p>${escapeHtml(error.message)}</p>`;
    }
  }

  modalBody?.addEventListener('click', event => {
    const item = event.target.closest('.rwth-job-meta-item--expandable');
    if (!item || !modalBody.contains(item)) return;
    toggleMetaItem(item);
  });

  modalBody?.addEventListener('keydown', event => {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    const item = event.target.closest('.rwth-job-meta-item--expandable');
    if (!item || !modalBody.contains(item)) return;
    event.preventDefault();
    toggleMetaItem(item);
  });

  function closeModal() {
    if (!modal) return;
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('rwth-jobs-modal-open');
  }

  tableBody?.addEventListener('click', async (event) => {
    const button = event.target.closest('button[data-action]');
    const row = event.target.closest('tr.rwth-job-row');
    if (!row) return;

    if (button) {
      event.stopPropagation();
      button.disabled = true;
      try {
        if (button.dataset.action === 'delete') {
          await changeStatus(row, 'deleted');
        } else if (button.dataset.action === 'status') {
          await changeStatus(row, button.dataset.targetStatus || 'interesting');
        }
      } catch (error) {
        showToast(error.message || 'Status konnte nicht geändert werden.', true);
      } finally {
        if (document.body.contains(button)) button.disabled = false;
      }
      return;
    }

    openJobModal(row.dataset.jobId);
  });

  tableBody?.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    if (event.target.closest('button')) return;
    const row = event.target.closest('tr.rwth-job-row');
    if (!row) return;
    event.preventDefault();
    openJobModal(row.dataset.jobId);
  });

  syncButton?.addEventListener('click', async () => {
    const oldText = syncButton.textContent;
    syncButton.disabled = true;
    syncButton.textContent = 'Aktualisiere…';
    try {
      const result = await postAjax('sync');
      const errors = Array.isArray(result.errors) ? result.errors.length : 0;
      showToast(`Fetch fertig: ${result.discovered} gefunden, ${result.saved} gespeichert/aktualisiert${errors ? `, ${errors} Fehler` : ''}.`);
      window.setTimeout(() => window.location.reload(), 600);
    } catch (error) {
      showToast(error.message || 'RWTH-Fetch fehlgeschlagen.', true);
      syncButton.disabled = false;
      syncButton.textContent = oldText;
    }
  });

  modalClose?.addEventListener('click', closeModal);
  modalClose?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      closeModal();
    }
  });
  modal?.addEventListener('click', (event) => {
    if (event.target === modal) closeModal();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && modal && !modal.classList.contains('hidden')) closeModal();
  });
})();
</script>

</body>
</html>
