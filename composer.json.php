<?php

// Setup PDO
$dsn = "mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=SYS_PRD_BND;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, "root", "", $options);

    // Fetch dependencies
    $stmt = $pdo->query("SELECT VendorName, PackageName, VersionOrBranch FROM Composer ORDER BY VendorName, PackageName");
    $require = [];
    foreach ($stmt as $row) {
        $require["{$row['VendorName']}/{$row['PackageName']}"] = $row['VersionOrBranch'];
    }

    // Fetch repositories
    $stmt = $pdo->query("SELECT RepositoryType, RepositoryUrl FROM Composer WHERE RepositoryUrl IS NOT NULL ORDER BY VendorName, PackageName");
    $repositories = [];
    foreach ($stmt as $row) {
        $repositories[] = [
            'type' => $row['RepositoryType'],
            'url'  => $row['RepositoryUrl'],
        ];
    }

    // Build composer.json content
    $composerJson = [
        'require' => $require,
    ];
    if (!empty($repositories)) {
        $composerJson['repositories'] = $repositories;
    }

    // Write to file
    file_put_contents('php://stdout', json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents("php://stderr", "✅ composer.json generated successfully.\n");

} catch (PDOException $e) {
    file_put_contents("php://stderr","❌ Database error: " . $e->getMessage() . "\n");
}
