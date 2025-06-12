<?php

$dsn = "mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=SYS_PRD_BND;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, "root", "", $options);

    $stmt = $pdo->query("SELECT PackageName, VersionOrTag FROM Npm ORDER BY PackageName");
    $dependencies = [];
    foreach ($stmt as $row) {
        $ver = $row['VersionOrTag'];
        $dependencies[$row['PackageName']] = $ver ?: '*';
    }

    $packageJson = [
        'type' => 'module',
        'dependencies' => (object)$dependencies,
    ];

    file_put_contents('php://stdout', json_encode($packageJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents('php://stderr', "✅ package.json generated successfully.\n");

} catch (PDOException $e) {
    file_put_contents('php://stderr', "❌ Database error: " . $e->getMessage() . "\n");
}
