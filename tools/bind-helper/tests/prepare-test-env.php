<?php
require __DIR__.'/test-environment-guard.php';
$root=dirname(__DIR__,3);$file=$argv[1]??'';
$env=[];foreach(['DB_DATABASE','BIND_TEST_DATABASE','DB_HOST','DB_PORT','DB_CONNECTION','DB_USERNAME','DB_PASSWORD','BIND_TEST_SERVER_HOSTNAME'] as $key){$env[$key]=getenv($key);}
assert_bind_test_environment($env);
if(!$file||!str_starts_with($file,'/')||file_exists($file)||is_link($file))throw new RuntimeException('Use a fresh absolute XML destination');
$xml=new SimpleXMLElement('<phpunit/>');$xml->addAttribute('bootstrap',__DIR__.'/phpunit-bootstrap.php');$xml->addAttribute('colors','false');
$php=$xml->addChild('php');foreach($env+['APP_ENV'=>'testing','APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),'CACHE_STORE'=>'array','CACHE_DRIVER'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'array','LOG_CHANNEL'=>'null','BCRYPT_ROUNDS'=>'4','APP_MAINTENANCE_DRIVER'=>'file'] as $key=>$value){$e=$php->addChild('env');$e->addAttribute('name',$key);$e->addAttribute('value',(string)$value);$e->addAttribute('force','true');}
$suites=$xml->addChild('testsuites');foreach(['Unit','Feature'] as$name){$suite=$suites->addChild('testsuite');$suite->addAttribute('name',$name);$suite->addChild('directory',$root.'/tests/'.$name.'/Bind');}
umask(0077);$f=fopen($file,'x');if(!$f||fwrite($f,$xml->asXML())===false||!fflush($f)||!fsync($f))throw new RuntimeException('Could not persist guarded configuration');fclose($f);echo $file."\n";
