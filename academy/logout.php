<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);header('Allow: POST');exit;}
verify_csrf();if(academy_current_user())academy_access_end('logout');$_SESSION=[];session_destroy();setcookie('melas_academy_v1','',['expires'=>time()-3600,'path'=>'/academy/','secure'=>!academy_local_test(),'httponly'=>true,'samesite'=>'Lax']);redirect_to('login.php');
