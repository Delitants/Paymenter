<?php
$path=__DIR__.'/test-environment-guard.php';if(is_file($path))require $path;
if(!function_exists('assert_bind_test_environment')){fwrite(STDERR,"FAIL: disposable database guard is missing\n");exit(1);}
$good=['DB_DATABASE'=>'bind_test_'.str_repeat('a',32),'BIND_TEST_DATABASE'=>'bind_test_'.str_repeat('a',32),'DB_HOST'=>'127.0.0.1','DB_PORT'=>'33070','DB_CONNECTION'=>'mariadb','BIND_TEST_SERVER_HOSTNAME'=>'bind-test-'.str_repeat('a',32)];
assert_bind_test_environment($good);$n=1;
foreach(['DB_DATABASE'=>['paymenter','paymenter_test','paymenter_migration_example','bind_test_bad'],'DB_HOST'=>['localhost','192.0.2.10','198.51.100.10','0.0.0.0'],'DB_PORT'=>['0','3306;rm','65536'],'DB_CONNECTION'=>['sqlite'],'BIND_TEST_DATABASE'=>['different'],'BIND_TEST_SERVER_HOSTNAME'=>['paymenter','']]as$key=>$values)foreach($values as$value){$bad=$good;$bad[$key]=$value;$denied=false;try{assert_bind_test_environment($bad);}catch(RuntimeException $e){$denied=true;}if(!$denied){fwrite(STDERR,"FAIL: accepted $key\n");exit(1);}$n++;}
echo "PASS: $n disposable database guard cases\n";
