<?php
require __DIR__ . "/connect.php";

$pdo = db();

echo $pdo->query("SELECT NOW()")->fetchColumn();