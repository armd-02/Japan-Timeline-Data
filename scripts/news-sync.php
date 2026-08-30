<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("news-sync.php is available from cron/CLI only.\n");
}

set_time_limit(0);

const WDQS_ENDPOINT = 'https://query.wikidata.org/sparql';
const WIKIDATA_API = 'https://www.wikidata.org/w/api.php';
const QUERY_LIMIT = 500;
const MAX_SPLIT_DEPTH = 2;
const MIN_DATE_PRECISION = 10; // 9=year, 10=month, 11=day
const NATIONAL_LIMIT = 10;

$rootDir = dirname(__DIR__);
$userAgent = trim((string) (
    getenv('JAPAN_TIMELINE_USER_AGENT')
    ?: 'JapanTimelineData/1.0 (+https://github.com/armd-02/Japan-Timeline-Data)'
));
$outputDir = $rootDir . '/data';
$prefectureGeoJsonPath = trim((string) (
    getenv('JAPAN_TIMELINE_PREFECTURES')
    ?: ($rootDir . '/reference/prefectures.min.geojson')
));

if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
    throw new RuntimeException("Could not create output directory: {$outputDir}");
}

// Prevent overlapping cron runs.
$lockPath = sys_get_temp_dir() . '/japan-timeline-news-sync-' . sha1($rootDir) . '.lock';
$lockHandle = fopen($lockPath, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    exit("news sync skipped: another process is still running.\n");
}

// Search Japan in several boxes rather than one huge WDQS query.
$searchBoxes = [
    ['name' => 'southwest',      'west' => 122.0, 'south' => 24.0, 'east' => 136.0, 'north' => 36.5],
    ['name' => 'central',        'west' => 134.0, 'south' => 33.0, 'east' => 142.5, 'north' => 39.5],
    ['name' => 'north',          'west' => 138.0, 'south' => 38.0, 'east' => 147.0, 'north' => 46.0],
    ['name' => 'south-pacific',  'west' => 135.0, 'south' => 20.0, 'east' => 143.5, 'north' => 34.0],
    ['name' => 'east-pacific',   'west' => 143.0, 'south' => 20.0, 'east' => 154.0, 'north' => 35.0],
];

// Keep SPARQL queries small. Merge/deduplicate in PHP.
//
// Event dates are searched both on the item itself and via P276 (location).
// Lifecycle dates are searched on items with their own coordinates. This keeps
// the number and cost of WDQS queries bounded while still catching map-change
// candidates such as openings, establishments, closures and demolitions.
$queryPatterns = [
    ['date_property' => 'P585', 'location_mode' => 'direct'],
    ['date_property' => 'P580', 'location_mode' => 'direct'],
    ['date_property' => 'P585', 'location_mode' => 'place'],
    ['date_property' => 'P580', 'location_mode' => 'place'],
    ['date_property' => 'P1619', 'location_mode' => 'direct'], // date of official opening
    ['date_property' => 'P571',  'location_mode' => 'direct'], // inception
    ['date_property' => 'P3999', 'location_mode' => 'direct'], // date of official closure
    ['date_property' => 'P576',  'location_mode' => 'direct'], // dissolved/abolished/demolished
];
$nationalDateProperties = ['P585', 'P580', 'P1619', 'P571', 'P3999', 'P576'];

function datePropertyDefinition(string $property): array
{
    return match ($property) {
        // P585 and P580 describe the event itself and should collapse to one
        // monthly news item when they refer to the same QID.
        'P585' => [
            'event_type' => 'event',
            'event_group' => 'event',
            'property_label' => 'point in time',
            'priority' => 50,
        ],
        'P580' => [
            'event_type' => 'start',
            'event_group' => 'event',
            'property_label' => 'start time',
            'priority' => 40,
        ],

        // P1619 is preferred over generic inception when both exist.
        'P1619' => [
            'event_type' => 'opening',
            'event_group' => 'opening',
            'property_label' => 'date of official opening',
            'priority' => 50,
        ],
        'P571' => [
            'event_type' => 'inception',
            'event_group' => 'opening',
            'property_label' => 'inception',
            'priority' => 40,
        ],

        // P3999 is the more specific property for facility closure. P576 is
        // still useful for demolition, abolition and dissolution.
        'P3999' => [
            'event_type' => 'closure',
            'event_group' => 'closure',
            'property_label' => 'date of official closure',
            'priority' => 50,
        ],
        'P576' => [
            'event_type' => 'abolition_or_demolition',
            'event_group' => 'closure',
            'property_label' => 'dissolved, abolished or demolished date',
            'priority' => 40,
        ],
        default => throw new InvalidArgumentException('Unsupported date property: ' . $property),
    };
}

function findOldestMissingMonth(string $outputDir, string $startMonth = '2010-01'): ?string
{
    $cursor = new DateTimeImmutable($startMonth . '-01T00:00:00Z');
    $lastCompleteMonth = (new DateTimeImmutable('first day of this month 00:00:00', new DateTimeZone('UTC')))
        ->modify('-1 month');

    while ($cursor <= $lastCompleteMonth) {
        $month = $cursor->format('Y-m');
        if (!is_file($outputDir . '/' . $month . '.json')) {
            return $month;
        }
        $cursor = $cursor->modify('+1 month');
    }

    return null;
}

function requestJson(string $url, array $headers, int $timeout = 55): array
{
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $responseHeaders = [];
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }
                return $length;
            },
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body !== false && $status === 200) {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        }

        $retryable = $status === 429 || $status >= 500 || $errno === CURLE_OPERATION_TIMEDOUT;
        if (!$retryable || $attempt >= 3) {
            throw new RuntimeException(
                "HTTP request failed: status={$status} errno={$errno} {$error}"
            );
        }

        $wait = isset($responseHeaders['retry-after'])
            ? max(1, (int) $responseHeaders['retry-after'])
            : $attempt * 3;
        sleep($wait);
    }

    throw new RuntimeException('HTTP request failed.');
}

function buildSparql(
    array $box,
    string $dateProperty,
    string $locationMode,
    DateTimeImmutable $from,
    DateTimeImmutable $to
): string {
    // Validate via the central property definition so only known property IDs
    // can be interpolated into SPARQL.
    datePropertyDefinition($dateProperty);
    if (!in_array($locationMode, ['direct', 'place'], true)) {
        throw new InvalidArgumentException('Unsupported location mode.');
    }

    $west = (float) $box['west'];
    $south = (float) $box['south'];
    $east = (float) $box['east'];
    $north = (float) $box['north'];
    $fromText = $from->format('Y-m-d\TH:i:s\Z');
    $toText = $to->format('Y-m-d\TH:i:s\Z');

    if ($locationMode === 'direct') {
        $geoSubject = '?item';
        $locationJoin = '';
    } else {
        $geoSubject = '?place';
        $locationJoin = '?item wdt:P276 ?place .';
    }

    // Use statement value nodes so date precision can be inspected.
    // precision: 9=year, 10=month, 11=day.
    return <<<SPARQL
SELECT DISTINCT ?item ?date ?datePrecision ?coord
WHERE {
  SERVICE wikibase:box {
    {$geoSubject} wdt:P625 ?coord .
    bd:serviceParam wikibase:cornerSouthWest "Point({$west} {$south})"^^geo:wktLiteral .
    bd:serviceParam wikibase:cornerNorthEast "Point({$east} {$north})"^^geo:wktLiteral .
  }

  {$locationJoin}

  ?item p:{$dateProperty} ?dateStatement .
  ?dateStatement ps:{$dateProperty} ?date .
  hint:Prior hint:rangeSafe true .
  ?dateStatement psv:{$dateProperty} ?dateNode .
  ?dateNode wikibase:timePrecision ?datePrecision .

  FILTER(?datePrecision >= 10)
  FILTER(
    ?date >= "{$fromText}"^^xsd:dateTime &&
    ?date <  "{$toText}"^^xsd:dateTime
  )
}
LIMIT 500
SPARQL;
}

function buildNationalSparql(
    string $dateProperty,
    DateTimeImmutable $from,
    DateTimeImmutable $to
): string {
    datePropertyDefinition($dateProperty);

    $fromText = $from->format('Y-m-d\TH:i:s\Z');
    $toText = $to->format('Y-m-d\TH:i:s\Z');

    // National candidates intentionally do not require coordinates. Country,
    // country of origin and applies-to-jurisdiction cover nationwide events,
    // systems, laws and organizations without mixing them into regional data.
    return <<<SPARQL
SELECT DISTINCT ?item ?date ?datePrecision
WHERE {
  ?item p:{$dateProperty} ?dateStatement .
  ?dateStatement ps:{$dateProperty} ?date .
  hint:Prior hint:rangeSafe true .
  ?dateStatement psv:{$dateProperty} ?dateNode .
  ?dateNode wikibase:timePrecision ?datePrecision .

  VALUES ?countryPredicate { wdt:P17 wdt:P495 wdt:P1001 }
  ?item ?countryPredicate wd:Q17 .

  FILTER(?datePrecision >= 10)
  FILTER(
    ?date >= "{$fromText}"^^xsd:dateTime &&
    ?date <  "{$toText}"^^xsd:dateTime
  )
}
LIMIT 500
SPARQL;
}

function executeWdqs(string $sparql, string $userAgent): array
{
    $url = WDQS_ENDPOINT . '?' . http_build_query(
        ['query' => $sparql, 'format' => 'json'],
        '',
        '&',
        PHP_QUERY_RFC3986
    );

    $json = requestJson($url, [
        'Accept: application/sparql-results+json',
        'User-Agent: ' . $userAgent,
    ], 55);

    // Be polite to the public endpoint.
    usleep(1000000);

    return $json['results']['bindings'] ?? [];
}

function queryBox(
    array $box,
    string $dateProperty,
    string $locationMode,
    DateTimeImmutable $from,
    DateTimeImmutable $to,
    string $userAgent,
    int $depth = 0
): array {
    $sparql = buildSparql($box, $dateProperty, $locationMode, $from, $to);

    fwrite(STDERR, sprintf(
        "query %s %s %s depth=%d\n",
        $box['name'],
        $dateProperty,
        $locationMode,
        $depth
    ));

    try {
        $rows = executeWdqs($sparql, $userAgent);
    } catch (RuntimeException $exception) {
        if ($depth >= MAX_SPLIT_DEPTH) {
            throw $exception;
        }
        fwrite(STDERR, "query failed; split box: {$exception->getMessage()}\n");
        return querySubBoxes(
            $box,
            $dateProperty,
            $locationMode,
            $from,
            $to,
            $userAgent,
            $depth + 1
        );
    }

    if (count($rows) >= QUERY_LIMIT && $depth < MAX_SPLIT_DEPTH) {
        fwrite(STDERR, "query reached limit; split box\n");
        return querySubBoxes(
            $box,
            $dateProperty,
            $locationMode,
            $from,
            $to,
            $userAgent,
            $depth + 1
        );
    }

    if (count($rows) >= QUERY_LIMIT) {
        throw new RuntimeException(
            "Query limit reached at max split depth for box: {$box['name']}"
        );
    }

    return $rows;
}

function querySubBoxes(
    array $box,
    string $dateProperty,
    string $locationMode,
    DateTimeImmutable $from,
    DateTimeImmutable $to,
    string $userAgent,
    int $depth
): array {
    $middleLon = ((float) $box['west'] + (float) $box['east']) / 2;
    $middleLat = ((float) $box['south'] + (float) $box['north']) / 2;

    $subBoxes = [
        ['name' => $box['name'] . '-sw', 'west' => $box['west'], 'south' => $box['south'], 'east' => $middleLon, 'north' => $middleLat],
        ['name' => $box['name'] . '-se', 'west' => $middleLon, 'south' => $box['south'], 'east' => $box['east'], 'north' => $middleLat],
        ['name' => $box['name'] . '-nw', 'west' => $box['west'], 'south' => $middleLat, 'east' => $middleLon, 'north' => $box['north']],
        ['name' => $box['name'] . '-ne', 'west' => $middleLon, 'south' => $middleLat, 'east' => $box['east'], 'north' => $box['north']],
    ];

    $result = [];
    foreach ($subBoxes as $subBox) {
        $result = array_merge(
            $result,
            queryBox(
                $subBox,
                $dateProperty,
                $locationMode,
                $from,
                $to,
                $userAgent,
                $depth
            )
        );
    }

    return $result;
}

function queryNational(
    string $dateProperty,
    DateTimeImmutable $from,
    DateTimeImmutable $to,
    string $userAgent,
    int $depth = 0
): array {
    $sparql = buildNationalSparql($dateProperty, $from, $to);

    fwrite(STDERR, sprintf(
        "query national %s %s..%s depth=%d\n",
        $dateProperty,
        $from->format('Y-m-d'),
        $to->format('Y-m-d'),
        $depth
    ));

    try {
        $rows = executeWdqs($sparql, $userAgent);
    } catch (RuntimeException $exception) {
        if ($depth >= MAX_SPLIT_DEPTH) {
            throw $exception;
        }
        fwrite(STDERR, "national query failed; split period: {$exception->getMessage()}\n");
        return queryNationalSubPeriods($dateProperty, $from, $to, $userAgent, $depth + 1);
    }

    if (count($rows) >= QUERY_LIMIT && $depth < MAX_SPLIT_DEPTH) {
        fwrite(STDERR, "national query reached limit; split period\n");
        return queryNationalSubPeriods($dateProperty, $from, $to, $userAgent, $depth + 1);
    }

    if (count($rows) >= QUERY_LIMIT) {
        throw new RuntimeException(
            "National query limit reached at max split depth for property: {$dateProperty}"
        );
    }

    return $rows;
}

function queryNationalSubPeriods(
    string $dateProperty,
    DateTimeImmutable $from,
    DateTimeImmutable $to,
    string $userAgent,
    int $depth
): array {
    $seconds = $to->getTimestamp() - $from->getTimestamp();
    if ($seconds < 2) {
        throw new RuntimeException('National query period cannot be split further.');
    }
    $middle = $from->modify('+' . intdiv($seconds, 2) . ' seconds');

    return array_merge(
        queryNational($dateProperty, $from, $middle, $userAgent, $depth),
        queryNational($dateProperty, $middle, $to, $userAgent, $depth)
    );
}

function getQid(string $uri): ?string
{
    return preg_match('/\/(Q\d+)$/', $uri, $matches) ? $matches[1] : null;
}

function parsePoint(string $wkt): ?array
{
    if (!preg_match('/Point\(\s*([-+0-9.]+)\s+([-+0-9.]+)\s*\)/', $wkt, $matches)) {
        return null;
    }
    return [(float) $matches[1], (float) $matches[2]];
}

function normalizeDate(string $rawDate, int $precision): ?string
{
    if ($precision >= 11) {
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $rawDate, $m) ? $m[0] : null;
    }
    if ($precision === 10) {
        return preg_match('/^\d{4}-\d{2}/', $rawDate, $m) ? $m[0] : null;
    }
    return null;
}

function datePrecisionLabel(int $precision): string
{
    return match ($precision) {
        10 => 'month',
        11 => 'day',
        default => $precision > 11 ? 'day-or-better' : 'year-or-coarser',
    };
}

function candidateScore(int $precision, string $dateProperty, string $locationMode): int
{
    $definition = datePropertyDefinition($dateProperty);

    // Date precision dominates. Within one semantic event group prefer the
    // more specific Wikidata property (for example P1619 over P571, or P3999
    // over P576), then direct coordinates over P276-derived coordinates.
    return ($precision * 1000)
        + ((int) $definition['priority'] * 10)
        + ($locationMode === 'direct' ? 1 : 0);
}

function pointInRing(float $lon, float $lat, array $ring): bool
{
    $inside = false;
    $count = count($ring);
    if ($count < 3) {
        return false;
    }

    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
        $xi = (float) $ring[$i][0];
        $yi = (float) $ring[$i][1];
        $xj = (float) $ring[$j][0];
        $yj = (float) $ring[$j][1];

        if (($yi > $lat) !== ($yj > $lat)
            && $lon < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi) {
            $inside = !$inside;
        }
    }

    return $inside;
}

function pointInPolygon(float $lon, float $lat, array $polygon): bool
{
    if (!$polygon || !pointInRing($lon, $lat, $polygon[0])) {
        return false;
    }
    foreach (array_slice($polygon, 1) as $hole) {
        if (pointInRing($lon, $lat, $hole)) {
            return false;
        }
    }
    return true;
}

function loadPrefectures(string $path): array
{
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException('Prefecture GeoJSON could not be read.');
    }

    $geoJson = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
    $areas = [];

    foreach ($geoJson['features'] ?? [] as $feature) {
        $properties = $feature['properties'] ?? [];
        $geometry = $feature['geometry'] ?? [];
        $name = trim((string) ($properties['name:ja'] ?? $properties['name'] ?? ''));
        $type = (string) ($geometry['type'] ?? '');
        $coordinates = $geometry['coordinates'] ?? null;

        if ($name === ''
            || !in_array($type, ['Polygon', 'MultiPolygon'], true)
            || !is_array($coordinates)) {
            continue;
        }

        $bounds = [INF, INF, -INF, -INF];
        $visit = static function (array $values) use (&$visit, &$bounds): void {
            if (isset($values[0], $values[1]) && is_numeric($values[0]) && is_numeric($values[1])) {
                $bounds[0] = min($bounds[0], (float) $values[0]);
                $bounds[1] = min($bounds[1], (float) $values[1]);
                $bounds[2] = max($bounds[2], (float) $values[0]);
                $bounds[3] = max($bounds[3], (float) $values[1]);
                return;
            }
            foreach ($values as $value) {
                if (is_array($value)) {
                    $visit($value);
                }
            }
        };
        $visit($coordinates);

        if (is_finite($bounds[0])) {
            $areas[] = compact('name', 'type', 'coordinates', 'bounds');
        }
    }

    if (count($areas) !== 47) {
        throw new RuntimeException('Prefecture GeoJSON must contain 47 usable prefectures.');
    }

    return $areas;
}

function findPrefecture(float $lat, float $lon, array $areas): ?string
{
    foreach ($areas as $area) {
        [$minLon, $minLat, $maxLon, $maxLat] = $area['bounds'];
        if ($lon < $minLon || $lon > $maxLon || $lat < $minLat || $lat > $maxLat) {
            continue;
        }

        $polygons = $area['type'] === 'Polygon' ? [$area['coordinates']] : $area['coordinates'];
        foreach ($polygons as $polygon) {
            if (pointInPolygon($lon, $lat, $polygon)) {
                return $area['name'];
            }
        }
    }

    return null;
}

function buildHeadlineDetails(string $title, ?string $description, string $eventType): array
{
    $title = trim($title);
    $description = trim((string) $description);
    $context = $title . ' ' . $description;

    if ($title === '') {
        return [
            'headline' => '',
            'category' => 'unknown',
            'rule' => 'unknown.empty',
        ];
    }

    // Classify the subject before interpreting the date property. This lets a
    // P580 start date describe the opening of a station or road facility while
    // leaving ordinary events unchanged.
    $category = match (true) {
        preg_match('/インターチェンジ|ジャンクション|(?:IC|JCT)(?:$|[（(])/iu', $context) === 1
            => 'interchange',
        preg_match('/サービスエリア|パーキングエリア|高速道路|自動車道|道路|バイパス|トンネル|歩道橋|(?:PA|SA)(?:$|[（(])/iu', $context) === 1
            => 'road_facility',
        preg_match('/(?:^|[「『（(])道の駅/u', $title) === 1
            || preg_match('/(?:^|の)道の駅/u', $description) === 1
            => 'roadside_station',
        preg_match('/駅(?!伝)|鉄道|路線/u', $context) === 1
            => 'railway_station',
        preg_match('/空港/u', $context) === 1
            => 'airport',
        preg_match('/美術館|博物館|図書館|資料館|文化館|記念館|文学館|科学館|パビリオン|劇場|ホール|会館/u', $context) === 1
            => 'museum',
        preg_match('/商業施設|ショッピングセンター|ショッピングモール|モール|百貨店|デパート|スーパーマーケット|パルコ|PARCO|ライフガーデン/iu', $context) === 1
            => 'commercial_facility',
        preg_match('/アトラクション|ライド|ジェットコースター|コースター/u', $context) === 1
            => 'attraction',
        preg_match('/病院|医療センター|メディカルセンター|診療所|クリニック/u', $context) === 1
            => 'medical_facility',
        preg_match('/屋内運動場|体育館|スポーツセンター|運動場|競技場|スタジアム/u', $context) === 1
            => 'sports_facility',
        preg_match('/公園|庭園|動物園|植物園|遊園地/u', $context) === 1
            => 'park',
        preg_match('/大会|選手権|リーグ|競走|駅伝|相撲|野球|サッカー/u', $context) === 1
            => 'sports_event',
        preg_match('/(?:市|町|村|郡|区)$/u', $title) === 1
            || preg_match('/自治体|地方公共団体|市町村/u', $description) === 1
            => 'municipality',
        preg_match('/大学|短期大学|高校|高等学校|中学校|小学校|学校|学院/u', $context) === 1
            => 'school',
        preg_match('/会社|企業|株式会社|有限会社|銀行/u', $context) === 1
            => 'company',
        preg_match('/機構|庁|省|局|委員会|協会|連盟|法人|団体|組織/u', $context) === 1
            => 'organization',
        preg_match('/建築物|建物|ビル|タワー|塔|複合施設|施設/u', $context) === 1
            => 'building',
        default => 'unknown',
    };

    $openingVerb = match ($category) {
        'interchange', 'road_facility' => '開通',
        'railway_station', 'roadside_station', 'airport', 'commercial_facility', 'building' => '開業',
        'museum' => '開館',
        'attraction' => 'オープン',
        'medical_facility', 'sports_facility' => '開設',
        'park' => '開園',
        'municipality', 'organization' => '発足',
        'school', 'company' => '設立',
        default => null,
    };

    if (in_array($eventType, ['opening', 'inception', 'start'], true) && $openingVerb !== null) {
        return [
            'headline' => $title . 'が' . $openingVerb,
            'category' => $category,
            'rule' => $category . '.open',
        ];
    }

    if ($eventType === 'closure') {
        return [
            'headline' => $title . 'が閉鎖',
            'category' => $category,
            'rule' => $category . '.close',
        ];
    }

    if ($eventType === 'abolition_or_demolition') {
        $verb = match ($category) {
            'company', 'organization' => '解散',
            'building', 'sports_facility' => '解体',
            default => '廃止',
        };
        $ruleAction = match ($verb) {
            '解散' => 'dissolve',
            '解体' => 'demolish',
            default => 'abolish',
        };
        return [
            'headline' => $title . 'が' . $verb,
            'category' => $category,
            'rule' => $category . '.' . $ruleAction,
        ];
    }

    if ($eventType === 'inception') {
        return [
            'headline' => $title . 'が設立',
            'category' => $category,
            'rule' => $category . '.inception_fallback',
        ];
    }

    if ($eventType === 'opening') {
        return [
            'headline' => $title . 'が開業',
            'category' => $category,
            'rule' => $category . '.opening_fallback',
        ];
    }

    return [
        'headline' => $title,
        'category' => $category,
        'rule' => $eventType . '.label',
    ];
}

function buildHeadline(string $title, ?string $description, string $eventType): string
{
    return buildHeadlineDetails($title, $description, $eventType)['headline'];
}

function classifyNationalCategory(
    string $title,
    ?string $description,
    string $headlineCategory
): string {
    $context = $title . ' ' . (string) $description;

    return match (true) {
        preg_match('/地震|津波|台風|豪雨|洪水|噴火|災害|土砂崩れ|雪崩/u', $context) === 1
            => 'disaster',
        preg_match('/事件|事故|テロ|殺人|爆発|火災|発砲|墜落/u', $context) === 1
            => 'major_incident',
        preg_match('/内閣|政権|選挙|法律|法令|条例|制度|政策|条約|省庁|国会/u', $context) === 1
            => 'politics',
        in_array($headlineCategory, [
            'interchange',
            'road_facility',
            'roadside_station',
            'railway_station',
            'airport',
        ], true) => 'infrastructure',
        $headlineCategory === 'municipality' => 'municipality',
        in_array($headlineCategory, ['museum', 'attraction', 'park'], true) => 'culture',
        in_array($headlineCategory, ['company', 'commercial_facility'], true) => 'economy',
        $headlineCategory === 'school' => 'education',
        $headlineCategory === 'sports_event'
            || preg_match('/大会|選手権|リーグ|競技|競走|駅伝|スポーツ|相撲|野球|サッカー/u', $context) === 1
            => 'sports',
        default => 'society',
    };
}

function nationalImportanceScore(array $event, array $candidateSources): array
{
    $category = classifyNationalCategory(
        (string) ($event['title'] ?? ''),
        is_string($event['description'] ?? null) ? $event['description'] : null,
        (string) ($event['headline_category'] ?? 'unknown')
    );
    $eventType = (string) ($event['event_type'] ?? 'unknown');
    $sitelinkCount = max(0, (int) ($event['sitelink_count'] ?? 0));

    $sitelinkScore = match (true) {
        $sitelinkCount >= 50 => 30,
        $sitelinkCount >= 20 => 20,
        $sitelinkCount >= 5 => 10,
        default => 0,
    };
    $categoryScore = match ($category) {
        'disaster', 'major_incident' => 30,
        'politics' => 25,
        'infrastructure' => 20,
        'municipality' => 15,
        'culture', 'economy' => 10,
        'sports' => 8,
        'education' => in_array($eventType, ['closure', 'abolition_or_demolition'], true) ? 2 : 5,
        default => 5,
    };
    $sourceOverlapScore = count(array_unique($candidateSources)) > 1 ? 5 : 0;

    $breakdown = [
        'japanese_wikipedia' => ($event['wikipedia_language'] ?? null) === 'ja' ? 20 : 0,
        'sitelinks' => $sitelinkScore,
        'category' => $categoryScore,
        'source_overlap' => $sourceOverlapScore,
    ];

    return [
        'category' => $category,
        'score' => array_sum($breakdown),
        'score_breakdown' => $breakdown,
    ];
}

function buildNationalCandidate(
    array $event,
    array $candidateSources,
    ?string $region = null
): array {
    $candidateSources = array_values(array_unique($candidateSources));
    sort($candidateSources);

    $importance = nationalImportanceScore($event, $candidateSources);
    $eventType = (string) ($event['event_type'] ?? 'unknown');
    $title = (string) ($event['title'] ?? '');
    if ($importance['category'] === 'politics'
        && in_array($eventType, ['opening', 'inception', 'start'], true)) {
        if (preg_match('/法律|法令|条例/u', $title) === 1) {
            $event['headline'] = $title . 'が制定';
            $event['headline_category'] = 'law';
            $event['headline_rule'] = 'national.law.enact';
        } elseif (preg_match('/制度|政策/u', $title) === 1) {
            $event['headline'] = $title . 'が開始';
            $event['headline_category'] = 'system';
            $event['headline_rule'] = 'national.system.start';
        } elseif (preg_match('/大臣|庁|省|局|委員会/u', $title) === 1) {
            $event['headline'] = $title . 'が設置';
            $event['headline_category'] = 'government';
            $event['headline_rule'] = 'national.government.establish';
        }
    }
    $event['category'] = $importance['category'];
    $event['score'] = $importance['score'];
    $event['score_breakdown'] = $importance['score_breakdown'];
    $event['candidate_sources'] = $candidateSources;
    if ($region !== null) {
        $event['region'] = $region;
    }

    return $event;
}

function compareNationalCandidates(array $left, array $right): int
{
    $scoreOrder = ((int) ($right['score'] ?? 0)) <=> ((int) ($left['score'] ?? 0));
    if ($scoreOrder !== 0) {
        return $scoreOrder;
    }

    $sitelinkOrder = ((int) ($right['sitelink_count'] ?? 0))
        <=> ((int) ($left['sitelink_count'] ?? 0));
    if ($sitelinkOrder !== 0) {
        return $sitelinkOrder;
    }

    return [
        (string) ($left['date'] ?? ''),
        (string) ($left['title'] ?? ''),
        (string) ($left['wikidata_id'] ?? ''),
    ] <=> [
        (string) ($right['date'] ?? ''),
        (string) ($right['title'] ?? ''),
        (string) ($right['wikidata_id'] ?? ''),
    ];
}

function selectNationalNews(array $candidates, int $limit): array
{
    usort($candidates, 'compareNationalCandidates');

    // Prevent one prolific class (notably municipality reorganizations or
    // station openings) from occupying the entire nationwide list.
    $categoryLimits = [
        'municipality' => 2,
        'infrastructure' => 2,
        'education' => 1,
        'economy' => 1,
        'culture' => 1,
        'sports' => 1,
        'society' => 1,
        'disaster' => 3,
        'major_incident' => 2,
        'politics' => 2,
    ];
    $selected = [];
    $selectedQids = [];
    $categoryCounts = [];

    foreach ($candidates as $candidate) {
        $category = (string) ($candidate['category'] ?? 'society');
        $categoryLimit = $categoryLimits[$category] ?? 1;
        if (($categoryCounts[$category] ?? 0) >= $categoryLimit) {
            continue;
        }

        $selected[] = $candidate;
        $selectedQids[(string) ($candidate['wikidata_id'] ?? '')] = true;
        $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
        if (count($selected) >= $limit) {
            return $selected;
        }
    }

    // Sparse months may not have enough categories. Fill remaining slots by
    // score while retaining QID uniqueness.
    foreach ($candidates as $candidate) {
        $qid = (string) ($candidate['wikidata_id'] ?? '');
        if (isset($selectedQids[$qid])) {
            continue;
        }
        $selected[] = $candidate;
        $selectedQids[$qid] = true;
        if (count($selected) >= $limit) {
            break;
        }
    }

    return $selected;
}

function fetchEntityMetadata(array $qids, string $userAgent): array
{
    $result = [];

    foreach (array_chunk($qids, 50) as $chunk) {
        $url = WIKIDATA_API . '?' . http_build_query(
            [
                'action' => 'wbgetentities',
                'ids' => implode('|', $chunk),
                'props' => 'labels|descriptions|sitelinks/urls',
                'languages' => 'ja|en',
                'format' => 'json',
                'formatversion' => '2',
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        $json = requestJson($url, [
            'Accept: application/json',
            'User-Agent: ' . $userAgent,
        ]);

        foreach ($json['entities'] ?? [] as $qid => $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $jaWiki = $entity['sitelinks']['jawiki']['url'] ?? null;
            $enWiki = $entity['sitelinks']['enwiki']['url'] ?? null;

            $result[$qid] = [
                'title' => $entity['labels']['ja']['value']
                    ?? $entity['labels']['en']['value']
                    ?? $qid,
                'description' => $entity['descriptions']['ja']['value']
                    ?? $entity['descriptions']['en']['value']
                    ?? null,
                'wikipedia_url' => $jaWiki ?? $enWiki,
                'wikipedia_language' => $jaWiki !== null
                    ? 'ja'
                    : ($enWiki !== null ? 'en' : null),
                'sitelink_count' => count($entity['sitelinks'] ?? []),
            ];
        }

        usleep(500000);
    }

    return $result;
}


function updateIndex(string $outputDir): void
{
    $files = glob($outputDir . '/????-??.json') ?: [];
    $months = [];
    foreach ($files as $file) {
        $month = basename($file, '.json');
        if (preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            $months[] = $month;
        }
    }
    sort($months, SORT_STRING);
    $months = array_values(array_unique($months));

    $index = [
        'schema_version' => 1,
        'generated_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
        'months' => $months,
    ];
    $json = json_encode(
        $index,
        JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
    );
    $indexPath = $outputDir . '/index.json';
    $tempPath = $indexPath . '.tmp';
    if (file_put_contents($tempPath, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Temporary index JSON could not be written.');
    }
    if (!rename($tempPath, $indexPath)) {
        throw new RuntimeException('Could not finalize index JSON.');
    }
}

$force = in_array('--force', $argv, true);
$requestedMonth = null;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--force') {
        continue;
    }
    $requestedMonth = $argument;
    break;
}

$month = $requestedMonth ?? findOldestMissingMonth($outputDir);
if ($month === null) {
    exit("No missing completed month.\n");
}
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    throw new InvalidArgumentException('Month must be YYYY-MM.');
}

$from = new DateTimeImmutable($month . '-01T00:00:00Z');
$to = $from->modify('+1 month');
$outputPath = $outputDir . '/' . $month . '.json';
$tempPath = $outputPath . '.tmp';

if (is_file($outputPath) && !$force) {
    exit("Already exists: {$outputPath}\nUse --force to regenerate manually.\n");
}
if (is_file($tempPath) && !unlink($tempPath)) {
    throw new RuntimeException("Could not remove stale temp file: {$tempPath}");
}

$prefectureAreas = loadPrefectures($prefectureGeoJsonPath);
$regionalEventsByKey = [];
$rawRows = 0;
$discardedCoarseDates = 0;
$rawRowsByProperty = [];
$eventCountsByType = [];
$nationalEventsByQid = [];
$nationalRawRows = 0;
$nationalRawRowsByProperty = [];

foreach ($searchBoxes as $box) {
    foreach ($queryPatterns as $pattern) {
        $rows = queryBox(
            $box,
            $pattern['date_property'],
            $pattern['location_mode'],
            $from,
            $to,
            $userAgent
        );

        foreach ($rows as $row) {
            $rawRows++;
            $rawRowsByProperty[$pattern['date_property']] =
                ($rawRowsByProperty[$pattern['date_property']] ?? 0) + 1;

            $qid = getQid($row['item']['value'] ?? '');
            $point = parsePoint($row['coord']['value'] ?? '');
            $rawDate = $row['date']['value'] ?? '';
            $precision = (int) ($row['datePrecision']['value'] ?? 0);

            if ($precision < MIN_DATE_PRECISION) {
                $discardedCoarseDates++;
                continue;
            }

            $date = normalizeDate($rawDate, $precision);
            if ($qid === null || $point === null || $date === null) {
                continue;
            }

            [$lon, $lat] = $point;
            $prefecture = findPrefecture($lat, $lon, $prefectureAreas);
            if ($prefecture === null) {
                continue;
            }

            $definition = datePropertyDefinition($pattern['date_property']);
            $eventKey = $qid . ':' . $definition['event_group'];
            $sourceName = $pattern['date_property'] . ':' . $pattern['location_mode'];
            $score = candidateScore($precision, $pattern['date_property'], $pattern['location_mode']);

            $candidate = [
                'date' => $date,
                'date_precision' => $precision,
                'date_precision_label' => datePrecisionLabel($precision),
                'date_property' => $pattern['date_property'],
                'date_property_label' => $definition['property_label'],
                'event_type' => $definition['event_type'],
                'wikidata_id' => $qid,
                'latitude' => $lat,
                'longitude' => $lon,
                'query_sources' => [$sourceName => true],
                '_score' => $score,
                '_prefecture' => $prefecture,
            ];

            if (!isset($regionalEventsByKey[$eventKey])) {
                $regionalEventsByKey[$eventKey] = $candidate;
                continue;
            }

            $existing = $regionalEventsByKey[$eventKey];
            $existing['query_sources'][$sourceName] = true;

            $replaceCore = $score > ($existing['_score'] ?? -1)
                || ($score === ($existing['_score'] ?? -1)
                    && $date < ($existing['date'] ?? '9999-99-99'))
                || ($score === ($existing['_score'] ?? -1)
                    && $date === ($existing['date'] ?? '9999-99-99')
                    && $prefecture < ($existing['_prefecture'] ?? ''));

            if ($replaceCore) {
                $candidate['query_sources'] = $existing['query_sources'];
                $regionalEventsByKey[$eventKey] = $candidate;
            } else {
                $regionalEventsByKey[$eventKey] = $existing;
            }
        }
    }
}

foreach ($nationalDateProperties as $dateProperty) {
    $rows = queryNational($dateProperty, $from, $to, $userAgent);

    foreach ($rows as $row) {
        $nationalRawRows++;
        $nationalRawRowsByProperty[$dateProperty] =
            ($nationalRawRowsByProperty[$dateProperty] ?? 0) + 1;

        $qid = getQid($row['item']['value'] ?? '');
        $precision = (int) ($row['datePrecision']['value'] ?? 0);
        $date = normalizeDate($row['date']['value'] ?? '', $precision);
        if ($qid === null || $precision < MIN_DATE_PRECISION || $date === null) {
            continue;
        }

        $definition = datePropertyDefinition($dateProperty);
        $sourceName = $dateProperty . ':national';
        $score = candidateScore($precision, $dateProperty, 'national');
        $candidate = [
            'date' => $date,
            'date_precision' => $precision,
            'date_precision_label' => datePrecisionLabel($precision),
            'date_property' => $dateProperty,
            'date_property_label' => $definition['property_label'],
            'event_type' => $definition['event_type'],
            'wikidata_id' => $qid,
            'query_sources' => [$sourceName => true],
            '_score' => $score,
        ];

        if (!isset($nationalEventsByQid[$qid])) {
            $nationalEventsByQid[$qid] = $candidate;
            continue;
        }

        $existing = $nationalEventsByQid[$qid];
        $existing['query_sources'][$sourceName] = true;
        $replaceCore = $score > ($existing['_score'] ?? -1)
            || ($score === ($existing['_score'] ?? -1)
                && $date < ($existing['date'] ?? '9999-99-99'));

        if ($replaceCore) {
            $candidate['query_sources'] = $existing['query_sources'];
            $nationalEventsByQid[$qid] = $candidate;
        } else {
            $nationalEventsByQid[$qid] = $existing;
        }
    }
}

$regionalQids = array_values(array_unique(array_map(
    static fn(array $event): string => (string) $event['wikidata_id'],
    array_values($regionalEventsByKey)
)));
$regionalQidSet = array_fill_keys($regionalQids, true);
$metadataQids = array_values(array_unique(array_merge(
    $regionalQids,
    array_keys($nationalEventsByQid)
)));
$metadata = fetchEntityMetadata($metadataQids, $userAgent);
$regions = [];
$totalEvents = 0;
$nationalCandidatePoolByQid = [];

foreach ($prefectureAreas as $area) {
    $regions[$area['name']] = [];
}

foreach ($regionalEventsByKey as $event) {
    $qid = (string) $event['wikidata_id'];
    $prefecture = $event['_prefecture'];
    $meta = $metadata[$qid] ?? [];
    unset($event['_score'], $event['_prefecture']);

    $event['query_sources'] = array_keys($event['query_sources']);
    sort($event['query_sources']);
    $event['title'] = $meta['title'] ?? $qid;
    $event['description'] = $meta['description'] ?? null;
    $event['wikipedia_url'] = $meta['wikipedia_url'] ?? null;
    $event['wikipedia_language'] = $meta['wikipedia_language'] ?? null;

    $eventType = (string) ($event['event_type'] ?? 'unknown');
    $headlineDetails = buildHeadlineDetails(
        (string) $event['title'],
        is_string($event['description']) ? $event['description'] : null,
        $eventType
    );
    $event['headline'] = $headlineDetails['headline'];
    $event['headline_category'] = $headlineDetails['category'];
    $event['headline_rule'] = $headlineDetails['rule'];
    $eventCountsByType[$eventType] = ($eventCountsByType[$eventType] ?? 0) + 1;

    $nationalEvent = $event;
    $nationalEvent['sitelink_count'] = (int) ($meta['sitelink_count'] ?? 0);
    $nationalCandidatePoolByQid[$qid] = buildNationalCandidate(
        $nationalEvent,
        ['regional'],
        $prefecture
    );

    $regions[$prefecture][] = $event;
    $totalEvents++;
}

$nationalCandidates = [];
foreach ($nationalEventsByQid as $qid => $event) {
    $meta = $metadata[$qid] ?? [];
    unset($event['_score']);

    $event['query_sources'] = array_keys($event['query_sources']);
    sort($event['query_sources']);
    $event['title'] = $meta['title'] ?? $qid;
    $event['description'] = $meta['description'] ?? null;
    $event['wikipedia_url'] = $meta['wikipedia_url'] ?? null;
    $event['wikipedia_language'] = $meta['wikipedia_language'] ?? null;
    $event['sitelink_count'] = (int) ($meta['sitelink_count'] ?? 0);

    $eventType = (string) ($event['event_type'] ?? 'unknown');
    $headlineDetails = buildHeadlineDetails(
        (string) $event['title'],
        is_string($event['description']) ? $event['description'] : null,
        $eventType
    );
    $event['headline'] = $headlineDetails['headline'];
    $event['headline_category'] = $headlineDetails['category'];
    $event['headline_rule'] = $headlineDetails['rule'];

    $nationalCandidate = buildNationalCandidate($event, ['national_query']);
    if (!isset($regionalQidSet[$qid])) {
        // Candidates already represented under regions remain available to
        // the ranking pool without being duplicated in the JSON payload.
        $nationalCandidates[] = $nationalCandidate;
    }

    if (isset($nationalCandidatePoolByQid[$qid])) {
        $merged = $nationalCandidatePoolByQid[$qid];
        $merged['query_sources'] = array_values(array_unique(array_merge(
            $merged['query_sources'] ?? [],
            $nationalCandidate['query_sources'] ?? []
        )));
        sort($merged['query_sources']);
        $nationalCandidatePoolByQid[$qid] = buildNationalCandidate(
            $merged,
            ['regional', 'national_query'],
            is_string($merged['region'] ?? null) ? $merged['region'] : null
        );
    } else {
        $nationalCandidatePoolByQid[$qid] = $nationalCandidate;
    }
}

usort($nationalCandidates, 'compareNationalCandidates');
$national = array_values($nationalCandidatePoolByQid);
$national = selectNationalNews($national, NATIONAL_LIMIT);

foreach ($regions as $prefecture => $events) {
    usort($events, static function (array $a, array $b): int {
        return [$a['date'], $a['title']] <=> [$b['date'], $b['title']];
    });
    $regions[$prefecture] = $events;
}

ksort($regions);
ksort($rawRowsByProperty);
ksort($nationalRawRowsByProperty);
ksort($eventCountsByType);

$output = [
    'year' => (int) $from->format('Y'),
    'month' => (int) $from->format('n'),
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'source' => 'Wikidata',
    'schema_version' => 6,
    'filters' => [
        'minimum_date_precision' => MIN_DATE_PRECISION,
        'minimum_date_precision_label' => 'month',
        'year_precision_items_excluded' => true,
        'date_properties' => ['P585', 'P580', 'P1619', 'P571', 'P3999', 'P576'],
        'lifecycle_location_mode' => 'direct',
        'national_country_properties' => ['P17', 'P495', 'P1001'],
        'national_limit' => NATIONAL_LIMIT,
        'national_score_version' => 1,
        'national_selection' => 'score_with_category_caps',
        'national_candidate_storage' => 'national_query_only_excluding_regional_duplicates',
    ],
    'stats' => [
        'raw_rows' => $rawRows,
        'raw_rows_by_property' => $rawRowsByProperty,
        'discarded_coarse_dates' => $discardedCoarseDates,
        'event_count' => $totalEvents,
        'event_counts_by_type' => $eventCountsByType,
        'national_raw_rows' => $nationalRawRows,
        'national_raw_rows_by_property' => $nationalRawRowsByProperty,
        'national_query_candidate_count' => count($nationalEventsByQid),
        'national_candidate_count' => count($nationalCandidates),
        'national_count' => count($national),
    ],
    'national' => $national,
    'national_candidates' => $nationalCandidates,
    'regions' => $regions,
];

$json = json_encode(
    $output,
    JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_THROW_ON_ERROR
);

if (file_put_contents($tempPath, $json . PHP_EOL, LOCK_EX) === false) {
    throw new RuntimeException('Temporary JSON could not be written.');
}

if (!rename($tempPath, $outputPath)) {
    throw new RuntimeException('Could not finalize JSON.');
}

updateIndex($outputDir);

echo "created: {$outputPath}\n";
echo "events: {$totalEvents}, raw rows: {$rawRows}, discarded coarse dates: {$discardedCoarseDates}\n";
echo "national: " . count($national) . ", candidates: " . count($nationalCandidates)
    . ", raw rows: {$nationalRawRows}\n";
