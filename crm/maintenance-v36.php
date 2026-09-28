<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/bootstrap.php';ensure_lead_schema();
$apply=in_array('--apply',$argv,true);$email=in_array('--send-internal-email',$argv,true);
$result=['mode'=>$apply?'apply':'preview','closed_applicants'=>sales_applicant_retention($apply),'internal_email'=>sales_internal_mail_run($apply&&$email)];
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
