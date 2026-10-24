<?php

echo "LISTENER STARTED \n";

$host = 'localhost';
$db = 'chefs_Db';
$user = 'chef_admin';
$pass = 'ChefsDbPass123';

try{
	$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	echo "Nice you have conneted again";
}catch (PDOException $e){
	die("Connection error: ". $e->getMessage() . "\n");
}
echo "\n listening for database events (Press CTRL+C to Stop)\n";

while (true){
	echo"[" . date('H:i:s') . "] Checking for new jobs \n";
	sleep(5);
}

?>
