<?php

$host =  'localhost';
$db =  'chefs_Db';
$user = 'chef_admin';
$pass = 'ChefsDbPass123';
$charset =  'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
	PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
	PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
	PDO::ATTR_EMULATE_PREPARES =>false,
];

try{
	$pdo = new PDO($dsn, $user, $pass, $options);
	echo "Congrats, You have successfully connected to something(remeber to change this)\n";

} catch (\PDOException $e){
	throw new \PDOException($e->getMessage(), (int)$e->getCode());
};

?>
