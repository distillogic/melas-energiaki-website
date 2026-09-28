<?php
declare(strict_types=1);
if(!defined('ACADEMY_ROOT')){http_response_code(403);exit;}

/** Scripted greeting/farewell adds no commercial facts, consent, score or account state. */
function academy_conversation_opening(array $case,string $scenario): array {
    $company=$case['brand']==='melas'?'τη MELAS ENERGIAKI':'τη Distillogic';
    $purpose=match($scenario){
        'panels'=>'Θα ήθελα να ρωτήσω αν υπάρχει διαθέσιμος φωτοβολταϊκός εξοπλισμός προς πώληση και ποιος είναι ο αρμόδιος.',
        'service'=>'Επικοινωνώ σχετικά με τις ανάγκες λειτουργίας και συντήρησης του φωτοβολταϊκού σας πάρκου.',
        'refusal'=>'Θα ήθελα να ρωτήσω αν υπάρχει ανάγκη σχετική με φωτοβολταϊκό εξοπλισμό ή υπηρεσίες στο πάρκο σας.',
        'proposal'=>'Επικοινωνώ για το αίτημά σας για πρόταση, ώστε να καταλάβω τι ακριβώς χρειάζεστε.',
        default=>'Θα ήθελα να συζητήσουμε αν υπάρχει κάποια καθημερινή διαδικασία της επιχείρησής σας που χρειάζεται καλύτερη ψηφιακή οργάνωση.',
    };
    return [
        ['customer','Πελάτης','Καλημέρα σας. Παρακαλώ;'],
        ['learner','Εκπρόσωπος · εισαγωγή σεναρίου','Καλημέρα σας. Ονομάζομαι Αλέξης και καλώ από '.$company.'. '.$purpose.' Είναι κατάλληλη στιγμή για μια σύντομη συζήτηση;'],
    ];
}
function academy_conversation_message(string $side,string $speaker,string $text): void {
    echo '<article class="sim-message sim-message-'.($side==='learner'?'learner':'customer').'"><span>'.e($speaker).'</span><p>'.e($text).'</p></article>';
}
function academy_conversation_farewell(): void {
    academy_conversation_message('learner','Εκπρόσωπος · κλείσιμο σεναρίου','Ευχαριστώ για τον χρόνο σας. Καλή συνέχεια.');
    academy_conversation_message('customer','Πελάτης','Καλή σας ημέρα.');
}
