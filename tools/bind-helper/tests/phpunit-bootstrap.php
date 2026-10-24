<?php
require __DIR__.'/test-environment-guard.php';
$env=[];foreach(['DB_DATABASE','BIND_TEST_DATABASE','DB_HOST','DB_PORT','DB_CONNECTION','BIND_TEST_SERVER_HOSTNAME'] as$key)$env[$key]=$_ENV[$key]??getenv($key);
assert_bind_test_environment($env);
// Check the actual server identity before Laravel or RefreshDatabase can boot.
$pdo=new PDO('mysql:host=127.0.0.1;port='.$env['DB_PORT'].';dbname='.$env['DB_DATABASE'],$_ENV['DB_USERNAME']??getenv('DB_USERNAME'),$_ENV['DB_PASSWORD']??getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>5]);
if($pdo->query('SELECT @@hostname')->fetchColumn()!==$env['BIND_TEST_SERVER_HOSTNAME'])throw new RuntimeException('Disposable server identity differs');
$pdo=null;require dirname(__DIR__,3).'/vendor/autoload.php';
