<?php
/**
 * Conexión a la base de datos 'ecowatts'
 * Configuración estándar para entorno local XAMPP / phpMyAdmin
 */

$host = 'localhost';
$dbname = 'ecowatts';
$username = 'root';
$password = '';

try {
    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
    $opciones = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ];

    $pdo = new PDO($dsn, $username, $password, $opciones);
} catch (PDOException $e) {
    // Si la base de datos aún no existe en phpMyAdmin, intentar conectarse sin dbname y crearla si es posible
    try {
        $pdo_init = new PDO("mysql:host={$host};charset=utf8mb4", $username, $password);
        $pdo_init->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo = new PDO($dsn, $username, $password, $opciones);
    } catch (PDOException $ex) {
        die("Error crítico de conexión a la base de datos MySQL en phpMyAdmin: " . $e->getMessage());
    }
}
?>