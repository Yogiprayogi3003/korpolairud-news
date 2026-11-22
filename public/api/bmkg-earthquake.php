<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

try {
    $c = require dirname(__DIR__, 2) . '/app/config/database.php';

    $pdo = new PDO(
        "mysql:host={$c['host']};dbname={$c['database']};charset={$c['charset']}",
        $c['username'],
        $c['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $pdo->query(
        "SELECT response_json, created_at
         FROM api_integration_cache
         ORDER BY id DESC LIMIT 100"
    );

    $result = null;

    foreach ($stmt as $row) {
        $json = json_decode($row['response_json'], true);

        if (
            !is_array($json) ||
            ($json['provider'] ?? '') !== 'bmkg_earthquake' ||
            ($json['resource'] ?? '') !== 'latest'
        ) {
            continue;
        }

        $quake = $json['data']['Infogempa']['gempa'] ?? null;

        if (!is_array($quake) || empty($quake['DateTime'])) {
            continue;
        }

        $result = [
            'success' => true,
            'provider' => 'BMKG',
            'fetched_at' => $json['fetched_at'] ?? null,
            'cache_created_at' => $row['created_at'],
            'earthquake' => [
                'date' => $quake['Tanggal'] ?? '',
                'time' => $quake['Jam'] ?? '',
                'datetime' => $quake['DateTime'],
                'magnitude' => $quake['Magnitude'] ?? '',
                'depth' => $quake['Kedalaman'] ?? '',
                'region' => $quake['Wilayah'] ?? '',
                'potential' => $quake['Potensi'] ?? '',
                'felt' => $quake['Dirasakan'] ?? '',
                'coordinates' => $quake['Coordinates'] ?? ''
            ]
        ];
        break;
    }

    if (!$result) {
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'message' => 'Data gempa BMKG belum tersedia.'
        ]);
        exit;
    }

    $fetched = strtotime($result['fetched_at'] ?? '');

    $result['stale'] = !$fetched || (time() - $fetched > 21600);

    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('BMKG public widget: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Layanan informasi BMKG sementara tidak tersedia.'
    ]);
}
