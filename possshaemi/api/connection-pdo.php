<?php

/*
 * connection-pdo.php
 * Shared PDO connection used by every API class via include.
 * Adjust host/dbname/username/password to match your local XAMPP/WAMP setup.
 */

$dbHost = "127.0.0.1";
$dbName = "pos_master_file";
$dbUsername = "root";
$dbPassword = "";

try {
    $conn = new PDO("mysql:host=$dbHost;port=3307;dbname=$dbName;charset=utf8mb4", $dbUsername, $dbPassword);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die(json_encode(["error" => "Connection failed: " . $e->getMessage()]));
}
