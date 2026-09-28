<?php
declare(strict_types=1);
if(!defined('CRM_ROOT')){http_response_code(403);exit;}
function sales_page_query(string $sql,array $params,string $key):array {
    // SQL is provided by this application's controllers; never by request data.
    $sql=preg_replace('/\s+LIMIT\s+\d+\s*$/i','',$sql);$page=max(1,min(100000,(int)($_GET[$key.'_page']??1)));
    $q=db()->prepare('SELECT COUNT(*) FROM ('.$sql.') page_count');$q->execute($params);$count=(int)$q->fetchColumn();$pages=max(1,(int)ceil($count/50));$page=min($page,$pages);
    $q=db()->prepare($sql.' LIMIT 50 OFFSET '.(($page-1)*50));$q->execute($params);
    $GLOBALS['portal_pages'][$key]=['page'=>$page,'pages'=>$pages,'count'=>$count];return $q->fetchAll();
}
function sales_page_controls(string $key):void {
    $p=$GLOBALS['portal_pages'][$key]??null;if(!$p)return;
    echo '<nav class="actions" aria-label="Σελίδες"><span>'.(int)$p['count'].' εγγραφές · '.(int)$p['page'].' / '.(int)$p['pages'].'</span>';
    foreach(['Προηγούμενη'=>$p['page']-1,'Επόμενη'=>$p['page']+1] as $label=>$page){if($page<1||$page>$p['pages'])continue;$query=['tab'=>is_string($_GET['tab']??null)?$_GET['tab']:'overview',$key.'_page'=>$page];echo '<a class="button" href="'.e(crm_url('sales-portal.php?'.http_build_query($query))).'">'.e($label).'</a>';}
    echo '</nav>';
}
