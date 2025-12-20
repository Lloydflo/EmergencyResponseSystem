<?php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "ers_db"; // change to your actual DB name

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    die("Database Connection Failed: " . mysqli_connect_error());
}
?>
