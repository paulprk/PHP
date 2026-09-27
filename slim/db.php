<?php
function getDB(): PDO
{
    $host = 'db';
    $dbname = 'seminariophp';
    $user = 'seminariophp';
    $pass = 'seminariophp';

    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}
