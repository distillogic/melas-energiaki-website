<?php
declare(strict_types=1);
if(!defined('CRM_ROOT')){http_response_code(403);exit;}
function personnel_shared_asset_hashes():?array {
    $result=[];foreach(['signature','stamp'] as $kind){$file=CRM_ROOT.'/private-assets/signatures/distillogic-'.$kind.'.png';if(!is_file($file))return null;$result[$kind]=hash_file('sha256',$file);}return $result;
}
function personnel_melas_asset_names():?array {
    if(is_file(CRM_ROOT.'/private-assets/signatures/melas-signature.png')&&is_file(CRM_ROOT.'/private-assets/signatures/melas-stamp.png'))return ['signature'=>'melas-signature.png','stamp'=>'melas-stamp.png'];
    $q=db()->query("SELECT setting_value FROM crm_settings WHERE setting_key='melas_shared_signing_assets_v36'");$saved=json_decode((string)$q->fetchColumn(),true);$hashes=personnel_shared_asset_hashes();
    if(!$hashes||!is_array($saved)||($saved['hashes']??null)!==$hashes)return null;
    return ['signature'=>'distillogic-signature.png','stamp'=>'distillogic-stamp.png'];
}
