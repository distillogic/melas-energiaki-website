<?php
declare(strict_types=1);
if(!defined('CRM_ROOT')){http_response_code(403);exit;}
/** Human-readable display labels; persisted workflow/status keys never change. */
function crm_stage_label_v38(string $key): string {
    return [
        'prospect'=>'Αρχική ευκαιρία','contacted'=>'Έγινε επικοινωνία',
        'decision_maker_found'=>'Βρέθηκε αρμόδιος','need_identified'=>'Εντοπίστηκε ανάγκη',
        'qualification_in_progress'=>'Συλλογή στοιχείων','submitted'=>'Προς αξιολόγηση',
        'under_review'=>'Υπό αξιολόγηση','qualified'=>'Εγκεκριμένο lead',
        'commercial_discussion'=>'Εμπορική συζήτηση','won'=>'Ολοκληρώθηκε',
        'lost'=>'Δεν προχώρησε','rejected'=>'Απορρίφθηκε',
    ][$key]??(lead_status_labels()[$key]??$key);
}
