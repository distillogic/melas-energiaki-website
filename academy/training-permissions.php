<?php
declare(strict_types=1);
if(!defined('ACADEMY_ROOT')){http_response_code(403);exit;}
function academy_training_capability(array $actor,string $capability):bool {
    if(empty($actor['active'])||!in_array($capability,['trainer','examiner'],true))return false;
    if(academy_is_admin($actor))return true;
    if(!academy_crm_enabled()||empty($actor['crm_user_id']))return false;
    try{
        $source=academy_crm_user((string)$actor['crm_user_id'],true);
        if(!$source||!academy_crm_allowed($source)||strcasecmp($source['email'],$actor['email'])!==0)return false;
        $exists=academy_crm_db()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales_staff_capabilities'");if(!(int)$exists->fetchColumn())return false;
        $q=academy_crm_db()->prepare('SELECT 1 FROM sales_staff_capabilities WHERE user_id=? AND capability=?');$q->execute([$source['id'],$capability]);return (bool)$q->fetchColumn();
    }catch(Throwable){return false;}
}
function academy_can_review_training(array $actor):bool {return academy_training_capability($actor,'trainer')||academy_training_capability($actor,'examiner');}
function academy_can_assess_training(array $actor):bool {return academy_training_capability($actor,'examiner');}
