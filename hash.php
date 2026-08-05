<?php
$password = 'username1234';
$hash = password_hash($password, PASSWORD_DEFAULT);
echo $hash;
?>