<?php
// Set these values for your local XAMPP/MySQL installation.
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'candidate_registration');
define('DB_USER', 'root');
define('DB_PASSWORD', '');

// Generate with: C:\xampp\php\php.exe -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
define('ADMIN_PASSWORD_HASH', '$2y$10$/OrDlL0woTSEe5Aae66Tx.1K8vuuyleatA9Fe.sv50PQ8zF.q/wJG');

function db(): PDO {
  static $connection = null;
  if ($connection instanceof PDO) return $connection;
  $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
  $connection = new PDO($dsn, DB_USER, DB_PASSWORD, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
  return $connection;
}
