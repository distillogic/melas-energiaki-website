<?php
declare(strict_types=1);
if(!defined('ACADEMY_ROOT')){http_response_code(403);exit;}

/** UI only: role visibility delegates to the same existing permission functions. */
function academy_ui_icon(string $name): string {
    $paths=[
        'book'=>'<path d="M4 4h6a3 3 0 0 1 3 3v14a4 4 0 0 0-4-3H4zM13 7a3 3 0 0 1 3-3h4v14h-3a4 4 0 0 0-4 3"/>',
        'grid'=>'<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'check'=>'<path d="m8 12 3 3 5-6"/><rect x="4" y="3" width="16" height="18" rx="3"/>',
        'chart'=>'<path d="M4 3v17h17M8 15v-4m5 4V6m5 9V9"/>',
        'call'=>'<path d="M4 13v-2a8 8 0 0 1 16 0v2M6 12H4v7h3v-7zM18 12h2v7h-3v-7zM20 19a3 3 0 0 1-3 3h-3"/>',
        'people'=>'<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6m2 4a5 5 0 0 1 3 5"/>',
        'file'=>'<path d="M14 3H5v18h14V8zM14 3v6h5M8 13h8M8 17h5"/>',
        'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'arrow'=>'<path d="M5 12h14m-5-5 5 5-5 5"/>',
    ];
    return '<svg class="school-ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$name]??$paths['grid']).'</svg>';
}
function academy_ui_link(string $path,string $label,string $icon,bool $active): void {
    echo '<a href="'.e(academy_url($path)).'"'.($active?' aria-current="page"':'').'>'.academy_ui_icon($icon).'<span>'.e($label).'</span></a>';
}
function academy_interface_header(string $title,array $user): void {
    $current=basename($_SERVER['SCRIPT_NAME']??'');
    $team=$current==='index.php'&&($_GET['view']??'')==='team';
    $learning=$current==='index.php'&&!$team;
    $steps=[['index.php','Μαθήματα & quiz',$learning],['practice.php','Πρακτική στα CRM',$current==='practice.php'],['final-exam.php','Τελική εξέταση',$current==='final-exam.php'],['supervised-calls.php','Εικονικές κλήσεις',in_array($current,['supervised-calls.php','call-run.php'],true)]];
    ?>
<!doctype html><html lang="el" data-scheme="light"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?> | Melas Sales Academy</title>
<link rel="icon" href="<?= e(academy_url('favicon.svg')) ?>" type="image/svg+xml">
<?php foreach(['shell.css?v=18','courses.css?v=25','access-log.css?v=36','academy-v37.css?v=37','theory.css?v=25','terms-v27.css?v=27'] as $file): ?><link rel="stylesheet" href="<?= e(academy_url($file)) ?>"><?php endforeach; ?>
<?php if($current==='practice.php'): ?><link rel="stylesheet" href="<?= e(academy_url('practice.css?v=31')) ?>"><script src="<?= e(academy_url('practice.js?v=31')) ?>" defer></script><?php endif; ?>
<?php if(in_array($current,['supervised-calls.php','call-run.php'],true)): ?><link rel="stylesheet" href="<?= e(academy_url('call-simulator.css?v=34')) ?>"><?php endif; ?>
<link rel="stylesheet" href="<?= e(academy_url('experience-v38.css?v=38')) ?>">
<script>try{document.documentElement.dataset.scheme=localStorage.getItem('melas-academy-theme')==='dark'?'dark':'light'}catch(e){}</script>
<script src="<?= e(academy_url('shell.js?v=18')) ?>" defer></script><script src="<?= e(academy_url('experience-v38.js?v=38')) ?>" defer></script>
</head><body class="school-app">
<a class="skip-link" href="#workspace-content">Στο περιεχόμενο</a>
<aside class="school-sidebar" id="school-navigation" aria-label="Πλαϊνό μενού Academy">
  <a class="school-brand" href="<?= e(academy_url()) ?>"><img src="<?= e(academy_url('melas-logo.png')) ?>" alt="Melas Energiaki"><span>MELAS<small>Sales Academy</small></span></a>
  <button class="school-menu-close" type="button" data-school-close aria-label="Κλείσιμο μενού">×</button>
  <nav aria-label="Πλοήγηση πλατφόρμας">
    <span class="school-nav-label">Η εκπαίδευσή μου</span>
    <?php academy_ui_link('index.php','Μαθήματα','book',$learning);academy_ui_link('practice.php','CRM Lab','grid',$current==='practice.php'); ?>
    <span class="school-nav-label">Αξιολόγηση</span>
    <?php academy_ui_link('final-exam.php','Τελική εξέταση','check',$current==='final-exam.php');academy_ui_link('supervised-calls.php','Εικονικές κλήσεις','call',in_array($current,['supervised-calls.php','call-run.php'],true));academy_ui_link('grades.php','Βαθμολογία','chart',$current==='grades.php');academy_ui_link('assessment.php','Τελική αξιολόγηση','check',$current==='assessment.php'); ?>
    <span class="school-nav-label">Βιβλιοθήκη</span>
    <?php academy_ui_link('handbook.php','Γενικές σημειώσεις','file',$current==='handbook.php');academy_ui_link('glossary.php','Λεξικό όρων','book',$current==='glossary.php'); ?>
    <?php if(can_view_academy_team($user)||academy_can_view_accounts($user)||academy_can_review_training($user)||academy_can_view_access_history($user)): ?><span class="school-nav-label">Διαχείριση εκπαίδευσης</span><?php endif; ?>
    <?php if(can_view_academy_team($user)){academy_ui_link('index.php?view=team','Πρόοδος ομάδας','chart',$team);academy_ui_link('retakes.php','Επανεξετάσεις','clock',$current==='retakes.php');} ?>
    <?php if(academy_can_view_accounts($user))academy_ui_link('accounts.php',academy_is_admin($user)?'Εκπαιδευόμενοι':'Λογαριασμοί','people',$current==='accounts.php'); ?>
    <?php if(academy_can_review_training($user))academy_ui_link('training-team.php','Εκπαιδευτική εποπτεία','people',$current==='training-team.php'); ?>
    <?php if(academy_can_view_access_history($user))academy_ui_link('access-log.php','Είσοδοι & έξοδοι','clock',$current==='access-log.php'); ?>
  </nav>
  <a class="school-profile" href="<?= e(academy_url('profile.php')) ?>" <?= $current==='profile.php'?'aria-current="page"':'' ?>><span class="school-avatar"><?= e(mb_substr($user['name'],0,1)) ?></span><span><strong><?= e($user['name']) ?></strong><small>Ο λογαριασμός μου</small></span><?= academy_ui_icon('arrow') ?></a>
</aside>
<button type="button" class="school-scrim" data-school-close aria-label="Κλείσιμο μενού"></button>
<header class="school-topbar"><button type="button" class="school-menu" data-school-menu aria-controls="school-navigation" aria-expanded="false" aria-label="Άνοιγμα μενού"><svg class="school-ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button><div class="school-breadcrumb"><span>Academy</span><i>/</i><strong><?= e($title==='Melas Sales Academy'?'Η εκπαίδευσή μου':$title) ?></strong></div><div class="school-user"><button type="button" class="school-theme" data-school-theme aria-label="Αλλαγή φωτεινού ή σκούρου θέματος">◐</button><form method="post" action="<?= e(academy_url('logout.php')) ?>"><?= csrf_field() ?><button class="school-logout" type="submit">Έξοδος</button></form></div></header>
<main id="workspace-content" class="school-main" tabindex="-1">
<?php academy_notice(); ?>
<?php if($learning||in_array($current,['practice.php','final-exam.php','supervised-calls.php','call-run.php'],true)): ?>
<nav class="school-learning-path" aria-label="Διαδρομή εκπαίδευσης · τρέχον σημείο, όχι βαθμολογία"><?php foreach($steps as $i=>[$path,$label,$active]): ?><a href="<?= e(academy_url($path)) ?>" <?= $active?'aria-current="step"':'' ?>><b><?= str_pad((string)($i+1),2,'0',STR_PAD_LEFT) ?></b><span><?= e($label) ?></span><?= academy_ui_icon('arrow') ?></a><?php endforeach; ?></nav>
<?php endif; ?>
<div class="academy-reference-bar"><small>Βιβλιοθήκη · προαιρετικό υλικό</small><a href="<?= e(academy_url('handbook.php?format=pdf')) ?>" target="_blank" rel="noopener">Γενικές σημειώσεις · PDF ↗</a><a href="<?= e(academy_url('handbook.php')) ?>">Πληροφορίες &amp; λήψη</a></div>
<?php
}
