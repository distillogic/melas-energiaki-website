<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';$user=require_login();
if(!can_manage_accounts($user)||!personnel_ceo($user)){http_response_code(403);exit('Απαιτείται ο λογαριασμός του Μελά.');}
personnel_schema();require_once __DIR__.'/signature-asset-policy.php';$hashes=personnel_shared_asset_hashes();$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();try{
  personnel_reauth($user,$_POST);
  if(!$hashes||($_POST['reviewed']??'')!=='1'||!hash_equals(hash('sha256',json_encode($hashes)),(string)($_POST['asset_hash']??'')))throw new InvalidArgumentException('Ελέγξτε τα τρέχοντα αρχεία υπογραφής/σφραγίδας.');
  $pdo=db();$pdo->beginTransaction();
  $value=json_encode(['hashes'=>$hashes,'approved_by'=>$user['id'],'approved_at'=>date(DATE_ATOM),'entity'=>'S. D. MELAS TRADING BUSINESS'],JSON_THROW_ON_ERROR);
  $pdo->prepare("INSERT INTO crm_settings(setting_key,setting_value) VALUES('melas_shared_signing_assets_v36',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$value]);
  personnel_audit($user,null,null,'signing_assets_approved',$value);$pdo->commit();flash('success','Τα συγκεκριμένα αρχεία εγκρίθηκαν. Κάθε έγγραφο εξακολουθεί να απαιτεί δική του έγκριση CEO.');redirect_to('personnel.php?templates=1');
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$error=$e instanceof InvalidArgumentException?$e->getMessage():'Η έγκριση δεν αποθηκεύτηκε.';}
}
render_header('Έγκριση εταιρικών αρχείων υπογραφής',$user);
?>
<section class="card form-section"><h1>Ίδια νομική οντότητα · εταιρική υπογραφή</h1><p>Ελέγξτε ότι η σφραγίδα και η υπογραφή παρακάτω είναι κατάλληλες για τη S. D. MELAS TRADING BUSINESS και τα έγγραφα MELAS ENERGEIAKI. Μην εγκρίνετε σφραγίδα με λάθος στοιχεία ή ακατάλληλη δραστηριότητα. Η έγκριση δεν υπογράφει κανένα έγγραφο και δεν παρακάμπτει τον κωδικό CEO ανά έγγραφο.</p>
<?php if($error): ?><p class="alert error"><?= e($error) ?></p><?php endif; ?>
<?php if($hashes): foreach(['signature'=>'Υπογραφή','stamp'=>'Σφραγίδα'] as $key=>$label): ?><h2><?= e($label) ?></h2><img width="260" alt="<?= e($label) ?> για έλεγχο" src="data:image/png;base64,<?= base64_encode((string)file_get_contents(CRM_ROOT.'/private-assets/signatures/distillogic-'.$key.'.png')) ?>"><?php endforeach; ?>
<form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="asset_hash" value="<?= e(hash('sha256',json_encode($hashes))) ?>"><label class="checkbox-row"><input type="checkbox" name="reviewed" value="1" required> Εγώ, Spiros Melas, εγκρίνω αυτά τα ακριβή αρχεία για τη συγκεκριμένη νομική οντότητα και δραστηριότητα.</label><label>Ο κωδικός μου<input type="password" name="actor_password" required autocomplete="current-password"></label><button class="button primary">Έγκριση αυτών των αρχείων</button></form>
<?php else: ?><p>Δεν βρέθηκαν τα υφιστάμενα εταιρικά αρχεία. Παραμένει διαθέσιμη η χειροκίνητη υπογραφή και μεταφόρτωση τελικού PDF μετά την έγκριση CEO.</p><?php endif; ?></section><?php render_footer(); ?>
