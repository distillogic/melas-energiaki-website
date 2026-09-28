<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$user=academy_require_login();
if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
$mode=$_GET['format']??'';
if(!is_string($mode)||!in_array($mode,['','pdf','download'],true)){http_response_code(400);exit('Μη έγκυρο αίτημα.');}
if($mode!==''){
    $pdf=require __DIR__.'/handbook-data-v37.php';
    if(!is_string($pdf)||!str_starts_with($pdf,'%PDF-')){http_response_code(503);exit('Το αρχείο δεν είναι προσωρινά διαθέσιμο.');}
    header('Content-Type: application/pdf');
    header('Content-Disposition: '.($mode==='download'?'attachment':'inline').'; filename="General-Sales-Training-Handbook-GR.pdf"');
    header('Content-Length: '.strlen($pdf));
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    session_write_close();
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $pdf;
    exit;
}
render_header('Γενικές σημειώσεις πωλήσεων',$user);
?>
<section class="ac-panel ac-lesson handbook-intro">
  <span class="ac-kicker">ΒΙΒΛΙΟΘΗΚΗ ΑΝΑΦΟΡΑΣ · ΠΡΟΑΙΡΕΤΙΚΗ ΜΕΛΕΤΗ</span>
  <h1>Γενικές σημειώσεις πωλήσεων</h1>
  <p>Το πρακτικό εγχειρίδιο «Η τέχνη και η επιστήμη των πωλήσεων» είναι διαθέσιμο όποτε το χρειάζεσαι: πριν από μια συνάντηση, για επανάληψη ή για προσωπική εξάσκηση.</p>
  <p><strong>Δεν είναι υποχρεωτικό μάθημα.</strong> Δεν προσθέτει προαπαιτούμενο, προσπάθεια ή βαθμό και η ανάγνωσή του δεν επηρεάζει το ποσοστό ολοκλήρωσης. Οι αρχές του μπορούν να χρησιμοποιούνται σε ερωτήσεις εφαρμογής των quiz, με σχετική επεξήγηση στο μάθημα.</p>
  <div class="handbook-actions"><a class="school-button" href="<?= e(academy_url('handbook.php?format=pdf')) ?>" target="_blank" rel="noopener">Άνοιγμα PDF σε νέα καρτέλα ↗</a><a class="school-button" href="<?= e(academy_url('handbook.php?format=download')) ?>">Λήψη για προσωπική μελέτη ↓</a></div>
  <h2>Τι θα βρεις μέσα</h2>
  <p>Προετοιμασία και ακρόαση, διερεύνηση αναγκών, αξία και εμπιστοσύνη, αντιρρήσεις και διαπραγμάτευση, σύνθετες πωλήσεις μεταξύ επιχειρήσεων, διαχείριση σχέσεων και καθοδήγηση ομάδας. Περιλαμβάνει ασκήσεις, περιπτώσεις εφαρμογής, 120 πρακτικές συμβουλές, γλωσσάρι και βιβλιογραφία.</p>
  <p>Το PDF διαθέτει αναζητήσιμο κείμενο και σελιδοδείκτες κεφαλαίων. Σε κινητό μπορεί να ανοίξει στην εφαρμογή προβολής PDF της συσκευής. Αν δεν ανοίγει νέα καρτέλα, χρησιμοποίησε τη λήψη.</p>
  <p class="ac-fineprint">Το υλικό είναι για εσωτερική εκπαίδευση. Τα παραδείγματα δεν αντικαθιστούν εγκεκριμένους εταιρικούς όρους, αρμοδιότητες ή έλεγχο από ειδικό. Το αρχείο δεν είναι δημόσιο· απαιτεί σύνδεση στην Academy.</p>
</section>
<?php render_footer(); ?>
