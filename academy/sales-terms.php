<?php
declare(strict_types=1);
if(!defined('ACADEMY_ROOT')&&!defined('CRM_ROOT')){http_response_code(403);exit;}
/** Plain-text definitions: never apply the renderer to HTML, attributes or answers. */
function sales_terms(): array
{
    static $terms;
    if(is_array($terms))return $terms;
    $terms = [
        'CRM'=>'Customer Relationship Management — σύστημα οργάνωσης πελατών, επαφών και εμπορικών ευκαιριών',
        'B2B'=>'Business to Business — πωλήσεις και συνεργασίες μεταξύ επιχειρήσεων',
        'DNC'=>'Do Not Contact — καταγεγραμμένη αντίρρηση για προωθητική επικοινωνία',
        'QC'=>'Quality Control — έλεγχος ποιότητας πριν από παρουσίαση ή παράδοση',
        'SEO'=>'Search Engine Optimization — βελτιστοποίηση για μηχανές αναζήτησης',
        'SMS'=>'Short Message Service — σύντομο γραπτό μήνυμα κινητής τηλεφωνίας',
        'GDPR'=>'General Data Protection Regulation — Γενικός Κανονισμός Προστασίας Δεδομένων',
        'UX'=>'User Experience — εμπειρία χρήστη κατά τη χρήση μιας υπηρεσίας',
        'UI'=>'User Interface — περιβάλλον αλληλεπίδρασης του χρήστη',
        'CTA'=>'Call To Action — προτροπή για συγκεκριμένη επόμενη ενέργεια',
        'O&M'=>'Operations & Maintenance — λειτουργία και συντήρηση μιας εγκατάστασης',
        'EPC'=>'Engineering, Procurement & Construction — μελέτη, προμήθεια εξοπλισμού και κατασκευή έργου',
        'RFQ'=>'Request for Quotation — αίτημα συγκεκριμένης οικονομικής προσφοράς',
        'RFP'=>'Request for Proposal — αίτημα ολοκληρωμένης τεχνικής και εμπορικής πρότασης',
        'BoQ'=>'Bill of Quantities — αναλυτικός πίνακας εργασιών, υλικών και ποσοτήτων',
        'PV'=>'Photovoltaic — φωτοβολταϊκό, που μετατρέπει το φως σε ηλεκτρική ενέργεια',
        'BESS'=>'Battery Energy Storage System — σύστημα αποθήκευσης ενέργειας σε μπαταρίες',
        'IPP'=>'Independent Power Producer — ανεξάρτητος παραγωγός ηλεκτρικής ενέργειας',
        'PPA'=>'Power Purchase Agreement — σύμβαση αγοράς ηλεκτρικής ενέργειας',
        'COD'=>'Commercial Operation Date — ημερομηνία έναρξης εμπορικής λειτουργίας του έργου',
        'CAPEX'=>'Capital Expenditure — επενδυτική δαπάνη για απόκτηση ή αναβάθμιση παγίων',
        'OPEX'=>'Operating Expenditure — δαπάνες καθημερινής λειτουργίας',
        'CEO'=>'Chief Executive Officer — διευθύνων σύμβουλος',
        'CFO'=>'Chief Financial Officer — οικονομικός διευθυντής',
        'KPI'=>'Key Performance Indicator — μετρήσιμος δείκτης απόδοσης',
        'ERP'=>'Enterprise Resource Planning — σύστημα διαχείρισης επιχειρησιακών πόρων και διαδικασιών',
        'API'=>'Application Programming Interface — προγραμματιστική διεπαφή επικοινωνίας μεταξύ εφαρμογών',
        'AI'=>'Artificial Intelligence — τεχνητή νοημοσύνη',
        'SLA'=>'Service Level Agreement — συμφωνία επιπέδου υπηρεσιών, όπως χρόνοι απόκρισης',
        'NDA'=>'Non-Disclosure Agreement — συμφωνία εμπιστευτικότητας',
        'PO'=>'Purchase Order — εντολή αγοράς',
        'EXW'=>'Ex Works — εμπορικός όρος παράδοσης στον συμφωνημένο χώρο του πωλητή',
        'SCADA'=>'Supervisory Control and Data Acquisition — σύστημα εποπτικού ελέγχου και συλλογής δεδομένων',
        'AC'=>'Alternating Current — εναλλασσόμενο ηλεκτρικό ρεύμα',
        'DC'=>'Direct Current — συνεχές ηλεκτρικό ρεύμα',
        'MPPT'=>'Maximum Power Point Tracking — παρακολούθηση του σημείου μέγιστης ισχύος',
        'PR'=>'Performance Ratio — δείκτης απόδοσης φωτοβολταϊκού συστήματος σε σχέση με την αναμενόμενη παραγωγή',
        'PID'=>'Potential Induced Degradation — υποβάθμιση απόδοσης που προκαλείται από διαφορά ηλεκτρικού δυναμικού',
        'EL'=>'Electroluminescence — ηλεκτροφωταύγεια, έλεγχος που μπορεί να αποκαλύψει εσωτερικές ατέλειες κυψελών',
        'I-V'=>'Current–Voltage — χαρακτηριστική καμπύλη ηλεκτρικού ρεύματος και τάσης',
        'Voc'=>'Open-Circuit Voltage — τάση ανοικτού κυκλώματος',
        'Isc'=>'Short-Circuit Current — ρεύμα βραχυκυκλώματος',
        'Vmp'=>'Voltage at Maximum Power — τάση στο σημείο μέγιστης ισχύος',
        'Imp'=>'Current at Maximum Power — ρεύμα στο σημείο μέγιστης ισχύος',
        'PERC'=>'Passivated Emitter and Rear Cell — τεχνολογία κυψέλης με παθητικοποίηση για περιορισμό απωλειών',
        'TOPCon'=>'Tunnel Oxide Passivated Contact — τεχνολογία επαφής κυψέλης με πολύ λεπτό στρώμα οξειδίου',
        'HJT'=>'Heterojunction Technology — τεχνολογία ετεροεπαφής σε φωτοβολταϊκές κυψέλες',
        'EVA'=>'Ethylene Vinyl Acetate — συμπολυμερές αιθυλενίου και οξικού βινυλίου, υλικό ενθυλάκωσης κυψελών',
        'IEA'=>'International Energy Agency — Διεθνής Οργανισμός Ενέργειας',
        'PVPS'=>'Photovoltaic Power Systems Programme — πρόγραμμα για φωτοβολταϊκά συστήματα ηλεκτροπαραγωγής',
        'NREL'=>'National Renewable Energy Laboratory — ονομασία του αμερικανικού εργαστηρίου στις εκπαιδευτικές πηγές που παρατίθενται',
        'PDF'=>'Portable Document Format — μορφή αρχείου εγγράφου σταθερής διάταξης',
        'CSV'=>'Comma-Separated Values — αρχείο πίνακα με τιμές χωρισμένες με διαχωριστικό',
        'URL'=>'Uniform Resource Locator — διεύθυνση μιας σελίδας ή άλλου πόρου στο διαδίκτυο',
        'HTTP'=>'Hypertext Transfer Protocol — πρωτόκολλο μεταφοράς ιστοσελίδων χωρίς ενσωματωμένη κρυπτογράφηση',
        'HTTPS'=>'Hypertext Transfer Protocol Secure — μεταφορά ιστοσελίδων με κρυπτογραφημένη σύνδεση',
        'ID'=>'Identifier — μοναδικό αναγνωριστικό εγγραφής',
        'DevOps'=>'Development & Operations — συνεργασία ανάπτυξης και λειτουργίας λογισμικού',
        'CV'=>'Curriculum Vitae — βιογραφικό σημείωμα',
        'MB'=>'Megabyte — μονάδα μεγέθους ψηφιακού αρχείου',
        'TL'=>'Team Leader — υπεύθυνος ομάδας',
        'ΦΠΑ'=>'Φόρος Προστιθέμενης Αξίας',
        'ΑΠΕ'=>'Ανανεώσιμες Πηγές Ενέργειας',
        'ΑΦΜ'=>'Αριθμός Φορολογικού Μητρώου',
        'ΔΟΥ'=>'Δημόσια Οικονομική Υπηρεσία — φορολογική υπηρεσία',
        'ΙΚΕ'=>'Ιδιωτική Κεφαλαιουχική Εταιρεία — εταιρική μορφή',
        'W'=>'Watt — βατ, μονάδα ηλεκτρικής ισχύος',
        'Wp'=>'Watt-peak — ονομαστική ισχύς αιχμής φωτοβολταϊκού σε πρότυπες συνθήκες δοκιμής',
        'kW'=>'Kilowatt — κιλοβάτ, 1.000 βατ ισχύος',
        'kWp'=>'Kilowatt-peak — 1.000 βατ ονομαστικής ισχύος αιχμής',
        'MW'=>'Megawatt — μεγαβάτ, 1.000 κιλοβάτ ισχύος',
        'MWp'=>'Megawatt-peak — 1.000 κιλοβάτ ονομαστικής ισχύος αιχμής',
        'kWh'=>'Kilowatt-hour — κιλοβατώρα, μονάδα ενέργειας και όχι ισχύος',
        'MWh'=>'Megawatt-hour — μεγαβατώρα, 1.000 κιλοβατώρες ενέργειας',
    ];
    $terms += require __DIR__.'/enterprise-terms-v37.php';
    return $terms;
}
function sales_term_aliases(): array
{
    return array_merge(array_combine(array_keys(sales_terms()),array_keys(sales_terms())),[
        'Β2Β'=>'B2B','BOQ'=>'BoQ','IV'=>'I-V','KPIs'=>'KPI','APIs'=>'API','O & M'=>'O&M',
    ]);
}
function sales_term_pattern(): string
{
    static $pattern;
    if($pattern)return $pattern;
    $aliases=array_keys(sales_term_aliases());usort($aliases,fn($a,$b)=>strlen($b)<=>strlen($a));
    // Skip URLs, emails and model/record identifiers; unit suffixes like 280W
    // are permitted. Case is intentional: W is a unit, a lower-case word is not.
    return $pattern='~(?:https?://[^\s<>]+|[\w.+-]+@[\w.-]+\.[\p{L}]{2,}|\b[A-Z]{2,}-[0-9][A-Z0-9-]*)(*SKIP)(*F)|(?<![\p{L}_])(?:'.implode('|',array_map(fn($s)=>preg_quote($s,'~'),$aliases)).')(?![\p{L}\p{N}_])~u';
}
function sales_terms_html(string $text,array &$seen): string
{
    $terms=sales_terms();$aliases=sales_term_aliases();$out='';$offset=0;
    preg_match_all(sales_term_pattern(),$text,$matches,PREG_OFFSET_CAPTURE);
    foreach($matches[0] as [$match,$at]){
        $out.=htmlspecialchars(substr($text,$offset,$at-$offset),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $key=$aliases[$match];$safe=htmlspecialchars($match,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$definition=htmlspecialchars($terms[$key],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $out.='<abbr title="'.$definition.'">'.$safe.'</abbr>';
        if(!isset($seen[$key])){$out.='<span class="term-definition"> ('.$definition.')</span>';$seen[$key]=true;}
        $offset=$at+strlen($match);
    }
    return $out.htmlspecialchars(substr($text,$offset),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}
