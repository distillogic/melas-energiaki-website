<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';start_crm_session();
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');
$error=null;$sent=!empty($_SESSION['application_sent']);unset($_SESSION['application_sent']);
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    verify_csrf();
    try{
        enforce_rate_limit('partner-application',4);
        if(!empty($_POST['company_fax']))throw new InvalidArgumentException('Η αίτηση δεν μπορεί να υποβληθεί.');
        sales_apply($_POST,isset($_FILES['cv'])&&is_array($_FILES['cv'])?$_FILES['cv']:null);
        $_SESSION['application_sent']=true;redirect_to('partner-apply.php');
    }catch(Throwable $e){$error=$e instanceof PDOException?'Η φόρμα δεν είναι ακόμη διαθέσιμη. Επικοινώνησε με την εταιρεία.':$e->getMessage();if($e instanceof PDOException)error_log('Applicant intake: '.$e->getMessage());}
}
?><!doctype html><html lang="el"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Γίνε συνεργάτης | Melas Energiaki</title><link rel="stylesheet" href="<?= e(crm_url('assets/crm.css')) ?>"></head><body class="auth-page"><main class="auth-card setup-card"><h1>Γίνε συνεργάτης πωλήσεων</h1><p>Πες μας λίγα λόγια για την εμπειρία και την περιοχή σου. Η αίτηση αξιολογείται από τη διοίκηση και δεν δημιουργεί αυτόματα λογαριασμό ή δικαίωμα εκπροσώπησης.</p>
<?php if($sent): ?><p class="alert success" role="status">Η αίτησή σου καταχωρίστηκε. Η ομάδα θα επικοινωνήσει μαζί σου.</p><?php endif; ?><?php if($error): ?><p class="alert error" role="alert"><?= e($error) ?></p><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="stack"><?= csrf_field() ?><input type="hidden" name="MAX_FILE_SIZE" value="4194304"><input type="hidden" name="company_fax" value=""><label>Ονοματεπώνυμο<input name="name" autocomplete="name" minlength="3" maxlength="150" required></label><label>Email<input type="email" name="email" autocomplete="email" maxlength="320" required></label><label>Περιοχή<input name="region" minlength="2" maxlength="150" required></label><label>Εμπειρία και ενδιαφέρον<textarea name="experience" rows="6" minlength="30" maxlength="4000" required></textarea></label><label>Βιογραφικό PDF (προαιρετικό, έως 4 MB)<input type="file" name="cv" accept="application/pdf"></label><p>Συμπλήρωσε μόνο στοιχεία που αφορούν τη συνεργασία. Μην συμπεριλάβεις ευαίσθητα προσωπικά δεδομένα.</p><label class="check-label"><input type="checkbox" name="contact_permission" value="1" required><span>Διάβασα την <a href="<?= e(crm_url('recruitment-privacy.php')) ?>" target="_blank" rel="noopener">ενημέρωση απορρήτου</a> και ζητώ επικοινωνία για την αξιολόγηση της αίτησής μου. Κλεισμένες/απορριφθείσες αιτήσεις και βιογραφικά διατηρούνται για 3 μήνες.</span></label><button class="button primary">Υποβολή αίτησης</button></form><p><a href="/">Επιστροφή στην ιστοσελίδα</a></p></main></body></html>
