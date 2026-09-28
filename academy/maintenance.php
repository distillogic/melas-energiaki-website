<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/bootstrap.php';
if(!academy_installed())throw new RuntimeException('Academy is not installed');
$count=academy_access_maintain();echo 'Academy access records expired: '.$count.PHP_EOL;
