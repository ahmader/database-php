<?php 

require_once __DIR__ . '/../vendor/autoload.php'; // Autoload files using Composer autoload

use Ahmader\Database;


$database = new Database('mysql', 'localhost', 'root', '', ''); // ($dbtype = [mysql,mssql], $dbhost='localhost',  $dbuser='root', $dbpassword='root', $dbname='test' )
if (!empty($database->last_error)) die($database->last_error()."\n");

echo "get rows...\n";

$rows = $database->get_results("select * from mh_users;");
if (!empty($database->last_error)) die($database->last_error()."\n");
print_r($rows);

echo"\n\n\n	";
