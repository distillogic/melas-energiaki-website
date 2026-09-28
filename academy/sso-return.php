<?php
declare(strict_types=1);
// Cross-site POST landing page MUST NOT start a session or set a cookie.
// The user's next same-site POST carries the original SameSite=Lax state cookie.
require __DIR__.'/bootstrap.php';
if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST'||!academy_sso_enabled()||(academy_config()['sso']['issuer']??'')!=='distillogic-crm') {http_response_code(400);exit('Ξεκίνα από την εταιρική σύνδεση Academy.');}
$payload=academy_input('payload');$signature=academy_input('signature');
if(strlen($payload)>6000||!preg_match('/^[a-zA-Z0-9_-]+$/D',$payload)||!preg_match('/^[a-f0-9]{64}$/D',$signature)){http_response_code(400);exit('Μη έγκυρη σύνδεση.');}
academy_auth_header('Επιστροφή στην Academy');
?>
<p>Πάτησε συνέχεια για να επαληθευτεί η εταιρική σύνδεση. Η ταυτότητα θα επιβεβαιωθεί από το ασφαλές εισιτήριο, όχι από το κείμενο αυτής της σελίδας.</p>
<form method="post" action="<?= e(academy_url('sso.php')) ?>"><input type="hidden" name="payload" value="<?= e($payload) ?>"><input type="hidden" name="signature" value="<?= e($signature) ?>"><button class="school-button" type="submit">Συνέχεια στην Academy</button></form>
<?php academy_auth_footer(); ?>
