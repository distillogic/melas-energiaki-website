<?php
declare(strict_types=1);
if(!defined('CRM_ROOT')){http_response_code(403);exit;}
function personnel_relationship_change(array $actor,string $uid,string $relationship):void {
    if(!can_manage_accounts($actor)||!in_array($relationship,['employee','contractor'],true))throw new InvalidArgumentException('Μη έγκυρη αλλαγή σχέσης.');
    $q=db()->prepare('SELECT * FROM personnel_onboarding WHERE user_id=? FOR UPDATE');$q->execute([$uid]);$record=$q->fetch();
    if(!$record)throw new InvalidArgumentException('Δεν βρέθηκε καρτέλα ένταξης.');
    $q=db()->prepare('SELECT COUNT(*) FROM personnel_documents WHERE user_id=? AND onboarding_cycle=?');$q->execute([$uid,$record['onboarding_cycle']]);
    if((int)$q->fetchColumn()>0)throw new InvalidArgumentException('Έχουν ήδη εκδοθεί έγγραφα αυτού του κύκλου. Η αλλαγή νομικής σχέσης χρειάζεται νέο συμφωνημένο κύκλο· δεν ξαναγράφουμε τα παλιά έγγραφα.');
    db()->prepare('UPDATE personnel_onboarding SET relationship=? WHERE user_id=?')->execute([$relationship,$uid]);
    personnel_audit($actor,$uid,null,'relationship_corrected',$record['relationship'].' -> '.$relationship.' · πριν από έκδοση εγγράφων, χωρίς αλλαγή πρόσβασης');
}
function personnel_assert_melas_template(array $template):void {
    // A corporate contact email on the shared legal entity is not another brand.
    $body=preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu','',(string)$template['body']);
    if(!preg_match('/MELAS\s+ENERG(?:E)?IAKI|ΜΕΛΑΣ\s+ΕΝΕΡΓΕΙΑΚΗ/iu',$body)||preg_match('/\bDISTILLOGIC\b/iu',$body))throw new InvalidArgumentException('Επιλέξτε εγκεκριμένο πρότυπο της MELAS ENERGEIAKI. Τα εταιρικά email @distillogic.gr επιτρέπονται, όχι κείμενα άλλης δραστηριότητας.');
}
function personnel_terms_fields(string $relationship):array {
    $common=['legal_name'=>'Πλήρες νόμιμο ονοματεπώνυμο / επωνυμία προσώπου','tax_id'=>'ΑΦΜ προσώπου','address'=>'Διεύθυνση προσώπου','starts_on'=>'Ημερομηνία έναρξης (ΕΕΕΕ-ΜΜ-ΗΗ)','scope'=>'Θέση / συγκεκριμένο αντικείμενο','duration'=>'Διάρκεια και, αν είναι ορισμένη, ημερομηνία λήξης'];
    return $common+($relationship==='employee'?[
        'gross_salary'=>'Μικτός βασικός μισθός και περίοδος καταβολής (χωριστά από προμήθειες)',
        'hours'=>'Ώρες και ημέρες εργασίας / πρόγραμμα','workplace'=>'Τόπος εργασίας / τηλεργασία',
        'leave_rules'=>'Άδειες, εφαρμοζόμενη συλλογική ρύθμιση και λοιποί νόμιμοι όροι'
    ]:[
        'deliverables'=>'Παραδοτέα και τρόπος ανεξάρτητης οργάνωσης',
        'fees'=>'Αμοιβές, προμήθειες και έξοδα / παραστατικά',
        'notice'=>'Ειδικά συμφωνημένη προειδοποίηση λύσης / διάρκεια',
        'settlement'=>'Μηνιαία εκκαθάριση και ειδικοί όροι πληρωμής'
    ]);
}
function personnel_structured_terms(array $input,string $relationship,string $kind):array {
    if(!in_array($kind,['employment','services'],true))return [];
    $result=[];
    foreach(personnel_terms_fields($relationship) as $key=>$label){
        $value=$input['contract_'.$key]??'';
        if(!is_string($value)||mb_strlen(trim($value))<2||mb_strlen($value)>2000||preg_match('/\[[^\]]+\]/u',$value))throw new InvalidArgumentException('Συμπληρώστε: '.$label.'.');
        $result[$key]=trim($value);
    }
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$result['starts_on']);
    if(!$date||$date->format('Y-m-d')!==$result['starts_on'])throw new InvalidArgumentException('Μη έγκυρη ημερομηνία έναρξης.');
    return $result;
}
function personnel_render_terms_fields(string $relationship):void {
    echo '<fieldset><legend>Ατομικά στοιχεία σύμβασης</legend><p>Υποχρεωτικά στην έκδοση κύριας σύμβασης. Για πολιτικές/NDA δεν απαιτείται νέα καταχώριση. Τα στοιχεία παγώνουν στην έκδοση του εγγράφου· ο μισθός δεν αντικαθίσταται από προμήθειες.</p>';
    foreach(personnel_terms_fields($relationship) as $key=>$label)echo '<label>'.e($label).'<input name="contract_'.e($key).'" maxlength="2000"'.($key==='starts_on'?' type="date"':'').'></label>';
    echo '</fieldset>';
}
