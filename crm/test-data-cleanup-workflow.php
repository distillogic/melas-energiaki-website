<?php
declare(strict_types=1);
if(!defined('CRM_ROOT')){http_response_code(403);exit;}
const TEST_CLEANUP_CUTOFF='2026-09-25 12:08:19';
function test_cleanup_schema():void {
    if(parse_url((string)(crm_config()['app']['site_url']??''),PHP_URL_HOST)!=='melasenergiaki.gr')throw new RuntimeException('Cleanup is restricted to the Melas deployment');
    db()->exec("CREATE TABLE IF NOT EXISTS crm_test_cleanup_backups(id CHAR(36) PRIMARY KEY,created_by CHAR(36) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,restored_at DATETIME NULL,sha256 CHAR(64) NOT NULL,summary_json TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS crm_test_cleanup_parts(backup_id CHAR(36) NOT NULL,part_no INT NOT NULL,content MEDIUMBLOB NOT NULL,PRIMARY KEY(backup_id,part_no)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function test_cleanup_allowed():array {return ['companies','sales_leads','lead_status_history','lead_attachments','lead_commissions','panel_purchase_payments','service_revenue_receipts','lead_service_components','lead_protection_events','sales_lead_pipeline','sales_requests','sales_team_overrides','sales_recurring_contracts','sales_recurring_periods','sales_recurring_agreements','sales_account_owners','company_documents','company_document_deliveries','communications','communication_project_briefs','website_enquiries','website_enquiry_documents','crm_lead_signals','crm_contact_checks','crm_contact_events','sales_settlement_items'];}
function test_cleanup_key(string $table,array $row,array $keys):string {return json_encode(array_map(fn($key)=>(string)$row[$key],$keys),JSON_THROW_ON_ERROR);}
/** All selection identifiers are obtained from server schema / selected rows, not request SQL. */
function test_cleanup_plan(bool $lock=false):array {
    $pdo=db();$meta=[];$keys=[];
    foreach($pdo->query('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll() as $t)$meta[$t['TABLE_NAME']]=$t['ENGINE'];
    foreach($pdo->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND CONSTRAINT_NAME='PRIMARY' ORDER BY TABLE_NAME,ORDINAL_POSITION")->fetchAll() as $r)$keys[$r['TABLE_NAME']][]=$r['COLUMN_NAME'];
    $fk=$pdo->query('SELECT TABLE_NAME child,COLUMN_NAME col,REFERENCED_TABLE_NAME parent,REFERENCED_COLUMN_NAME parent_col FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL')->fetchAll();
    $relations=$fk;
    foreach(['sales_lead_pipeline','sales_requests','sales_team_overrides','sales_recurring_contracts','crm_lead_signals'] as $t)$relations[]=['child'=>$t,'col'=>'lead_id','parent'=>'sales_leads','parent_col'=>'id'];
    foreach([['sales_recurring_periods','contract_id','sales_recurring_contracts'],['sales_recurring_agreements','contract_id','sales_recurring_contracts'],['sales_account_owners','company_id','companies']] as [$t,$c,$p])$relations[]=['child'=>$t,'col'=>$c,'parent'=>$p,'parent_col'=>'id'];
    foreach(['crm_contact_checks','crm_contact_events'] as $t)foreach(['lead'=>'sales_leads','company'=>'companies'] as $type=>$p)$relations[]=['child'=>$t,'col'=>'subject_id','parent'=>$p,'parent_col'=>'id','typecol'=>'subject_type','type'=>$type];
    foreach(['commission'=>'lead_commissions','override'=>'sales_team_overrides'] as $type=>$p)$relations[]=['child'=>'sales_settlement_items','col'=>'source_id','parent'=>$p,'parent_col'=>'id','typecol'=>'source_type','type'=>$type];
    $selected=[];$add=function(string $table,array $rows)use(&$selected,$keys,$meta):bool {
        if(!$rows)return false;
        if(!in_array($table,test_cleanup_allowed(),true)||strtolower((string)($meta[$table]??''))!=='innodb'||empty($keys[$table]))throw new RuntimeException('Unreviewed cleanup dependency: '.$table);
        $changed=false;foreach($rows as $row){
            if(isset($row['created_at'])&&$row['created_at']>TEST_CLEANUP_CUTOFF)throw new InvalidArgumentException('Νεότερη εγγραφή συνδέεται με παλιό τεστ. Χρειάζεται χωριστός έλεγχος, χωρίς διαγραφή.');
            if(isset($row['updated_at'])&&$row['updated_at']>TEST_CLEANUP_CUTOFF)throw new InvalidArgumentException('Εγγραφή τροποποιήθηκε μετά την επιβεβαίωση των τεστ. Χρειάζεται έλεγχος, χωρίς αυτόματη διαγραφή.');
            if($table==='company_documents'&&(!empty($row['client_signed_at'])||!empty($row['approved_at'])||!empty($row['final_signed_at'])))throw new InvalidArgumentException('Υπάρχει υπογεγραμμένο/εγκεκριμένο έγγραφο πελάτη. Το καθάρισμα σταμάτησε χωρίς διαγραφή.');
            $key=test_cleanup_key($table,$row,$keys[$table]);if(!isset($selected[$table][$key])){$selected[$table][$key]=$row;$changed=true;}
        }return $changed;
    };
    foreach(['companies','sales_leads'] as $t){$q=$pdo->prepare("SELECT * FROM `$t` WHERE created_at<=?".($lock?' FOR UPDATE':''));$q->execute([TEST_CLEANUP_CUTOFF]);$add($t,$q->fetchAll());}
    for($round=0;$round<30;$round++){
        $changed=false;foreach($relations as $r){
            if(empty($selected[$r['parent']])||!isset($meta[$r['child']]))continue;
            $values=array_unique(array_map(fn($row)=>(string)$row[$r['parent_col']],array_values($selected[$r['parent']])));if(!$values)continue;
            foreach(array_chunk($values,200) as $chunk){
                foreach([$r['child'],$r['col']] as $identifier)if(!preg_match('/^[a-zA-Z0-9_]+$/D',$identifier))throw new RuntimeException('Unsafe identifier');
                $sql='SELECT * FROM `'.$r['child'].'` WHERE `'.$r['col'].'` IN ('.implode(',',array_fill(0,count($chunk),'?')).')';$params=array_values($chunk);
                if(isset($r['type'])){$sql.=' AND `'.$r['typecol'].'`=?';$params[]=$r['type'];}
                $q=$pdo->prepare($sql.($lock?' FOR UPDATE':''));$q->execute($params);if($add($r['child'],$q->fetchAll()))$changed=true;
            }
        }if(!$changed)break;if($round===29)throw new RuntimeException('Unresolved cleanup dependency cycle');
    }
    // Foreign-key topological order: backup/restore parents first, delete children first.
    $order=[];$visiting=[];$visit=function(string $table)use(&$visit,&$order,&$visiting,$fk,$selected):void {
        if(in_array($table,$order,true))return;if(isset($visiting[$table]))throw new RuntimeException('Cyclic cleanup foreign keys');$visiting[$table]=true;
        foreach($fk as $r)if($r['child']===$table&&isset($selected[$r['parent']]))$visit($r['parent']);
        unset($visiting[$table]);$order[]=$table;
    };$names=array_keys($selected);sort($names);foreach($names as $t)$visit($t);
    $tables=[];foreach($order as $table){ksort($selected[$table]);$tables[$table]=array_values($selected[$table]);}
    return ['version'=>36,'cutoff'=>TEST_CLEANUP_CUTOFF,'tables'=>$tables,'keys'=>array_intersect_key($keys,$tables)];
}
function test_cleanup_bytes(array $plan):string {
    $encoded=$plan;foreach($encoded['tables'] as &$rows)foreach($rows as &$row)foreach($row as &$value)if($value!==null)$value=base64_encode((string)$value);unset($value,$row,$rows);
    $bytes=json_encode($encoded,JSON_THROW_ON_ERROR);if(strlen($bytes)>16*1024*1024)throw new InvalidArgumentException('Τα δοκιμαστικά δεδομένα ξεπερνούν το όριο ασφαλούς επαναφοράς. Χρειάζεται καθάρισμα από διαχειριστή βάσης με πλήρες backup.');return $bytes;
}
function test_cleanup_hash(array $plan):string {return hash('sha256',test_cleanup_bytes($plan));}
function test_cleanup_execute(array $actor,string $expected):string {
    sales_require_manager($actor);$pdo=db();$pdo->beginTransaction();try{
        $plan=test_cleanup_plan(true);if(!$plan['tables'])throw new InvalidArgumentException('Δεν υπάρχουν δοκιμαστικά δεδομένα στην κλειδωμένη ημερομηνία.');
        $bytes=test_cleanup_bytes($plan);if(!hash_equals($expected,hash('sha256',$bytes)))throw new InvalidArgumentException('Τα δεδομένα άλλαξαν μετά την προεπισκόπηση. Δεν διαγράφηκε τίποτα· ελέγξτε νέα λίστα.');
        $id=uuid_v4();$pdo->prepare('INSERT INTO crm_test_cleanup_backups(id,created_by,sha256,summary_json) VALUES(?,?,?,?)')->execute([$id,$actor['id'],hash('sha256',$bytes),json_encode(array_map('count',$plan['tables']),JSON_THROW_ON_ERROR)]);
        $q=$pdo->prepare('INSERT INTO crm_test_cleanup_parts(backup_id,part_no,content) VALUES(?,?,?)');foreach(str_split($bytes,262144) as $n=>$part)$q->execute([$id,$n,$part]);
        foreach(array_reverse(array_keys($plan['tables'])) as $table){$pk=$plan['keys'][$table];$q=$pdo->prepare('DELETE FROM `'.$table.'` WHERE '.implode(' AND ',array_map(fn($k)=>'`'.$k.'`=?',$pk)));foreach($plan['tables'][$table] as $row)$q->execute(array_map(fn($k)=>$row[$k],$pk));}
        sales_portal_event($actor,'test_cleanup',$id,'completed',['counts'=>array_map('count',$plan['tables']),'backup_sha256'=>hash('sha256',$bytes)]);$pdo->commit();return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function test_cleanup_restore(array $actor,string $id):void {
    sales_require_manager($actor);$pdo=db();$pdo->beginTransaction();try{
        $q=$pdo->prepare('SELECT * FROM crm_test_cleanup_backups WHERE id=? AND restored_at IS NULL FOR UPDATE');$q->execute([$id]);$backup=$q->fetch();if(!$backup)throw new InvalidArgumentException('Δεν υπάρχει διαθέσιμο αντίγραφο.');
        $q=$pdo->prepare('SELECT content FROM crm_test_cleanup_parts WHERE backup_id=? ORDER BY part_no');$q->execute([$id]);$bytes=implode('',$q->fetchAll(PDO::FETCH_COLUMN));if(!hash_equals($backup['sha256'],hash('sha256',$bytes)))throw new RuntimeException('Backup integrity failure');$plan=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
        foreach($plan['tables'] as $table=>$rows){if(!in_array($table,test_cleanup_allowed(),true))throw new RuntimeException('Unexpected backup table');foreach($rows as $row){$columns=array_keys($row);foreach($columns as $c)if(!preg_match('/^[a-zA-Z0-9_]+$/D',$c))throw new RuntimeException('Unexpected column');$q=$pdo->prepare('INSERT INTO `'.$table.'` (`'.implode('`,`',$columns).'`) VALUES('.implode(',',array_fill(0,count($columns),'?')).')');$q->execute(array_map(fn($v)=>$v===null?null:base64_decode($v,true),array_values($row)));}}
        $pdo->prepare('UPDATE crm_test_cleanup_backups SET restored_at=NOW() WHERE id=?')->execute([$id]);sales_portal_event($actor,'test_cleanup',$id,'restored',[]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
