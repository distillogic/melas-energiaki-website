<?php
declare(strict_types=1);
if(!defined('ACADEMY_ROOT')){http_response_code(403);exit;}
// Shared by the case, launch and result views: no trip back to the catalogue.
$navLesson=$result['lesson_id']??$lessonId;$navIds=array_keys($catalog);$navIndex=array_search($navLesson,$navIds,true);
$navMode=$result['mode']??$mode;$navPrevious=$navIndex!==false&&$navIndex>0?$navIds[$navIndex-1]:null;
$navNext=$navIndex!==false?($navIds[$navIndex+1]??null):null;
?>
<nav class="lab-routebar" aria-label="Πλοήγηση ασκήσεων">
<div class="lab-route-links"><?php if($navPrevious): ?><a href="<?= e(academy_url('practice.php?lesson='.$navPrevious.'&mode='.$navMode)) ?>">← Προηγούμενη άσκηση</a><?php endif; ?><?php if($navNext): ?><a href="<?= e(academy_url('practice.php?lesson='.$navNext.'&mode='.$navMode)) ?>">Επόμενη άσκηση →</a><?php endif; ?></div>
<form method="get" action="<?= e(academy_url('practice.php')) ?>"><label for="lab-jump">Άμεση μετάβαση σε άσκηση</label><div><select id="lab-jump" name="lesson"><?php foreach($catalog as $key=>$item): ?><option value="<?= e($key) ?>" <?= $navLesson===$key?'selected':'' ?>><?= e($item['number'].' · '.$item['title']) ?></option><?php endforeach; ?></select><input type="hidden" name="mode" value="<?= e($navMode) ?>"><button class="lab-button lab-outline" type="submit">Άνοιγμα</button></div></form>
</nav>
