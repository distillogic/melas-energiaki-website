# MELAS ENERGIAKI CRM — Plesk edition

Independent PHP 8.2 / MariaDB CRM for `https://melasenergiaki.gr/crm/`.

## Included in this first version

- Dashboard
- Company records
- Communications and reminders
- Professional proposals
- PDF upload, CEO Approval code and locked approved PDF
- Sending approved proposals by email
- User accounts and activity history

The Distillogic source CRM remains separate and is not used at runtime.
Website enquiries are intentionally disabled because the current public website has no submission form. Communications are entered manually.

## Plesk requirements

- PHP 8.2+
- MariaDB 10.11+
- PDO MySQL, mbstring, fileinfo, curl and session PHP extensions
- HTTPS

## Installation

1. In Plesk, open the document root of `melasenergiaki.gr` and create/open the `crm` directory.
2. Upload this ZIP inside `/crm` and extract it there. Files such as `index.php`, `setup.php`, `assets` and `api` must appear directly inside `/crm` — never inside `/crm/crm`.
3. In Plesk create a MariaDB database and database user.
4. Copy `config.example.php` to `config.php` inside `/crm`.
5. Enter the database credentials, company details, email sender, CEO approval email and a long random setup token in `config.php`.
6. Visit `https://melasenergiaki.gr/crm/setup.php` once.
7. Enter the setup token and re-enter the existing passwords for the owner, administrator and support accounts.
8. Sign in at `https://melasenergiaki.gr/crm/`.

Never publish or commit `config.php`. After setup, keep the setup token private.

## v15 — κατοχύρωση συνεργάτη και προστατευμένα leads

- Ο αρχικός καταχωρητής (`submitted_by`) είναι ο σταθερός δικαιούχος. Δεν υπάρχει ανάθεση/μεταβίβαση lead. Παλαιές αναθέσεις δεν δίνουν πρόσβαση.
- Η καταχώριση κατοχυρώνει την προέλευση, όχι αυτομάτως αμοιβή. Ο δημιουργός βλέπει τα δικά του στοιχεία. Η διοίκηση βλέπει πριν την αποδοχή μόνο ελεγχόμενα ανώνυμα στοιχεία.
- Αποδοχή + €60 + αποκάλυψη είναι μία συναλλαγή. Απαιτούνται πλήρη στοιχεία, έλεγχος κριτηρίων και ρητή αποδοχή αμοιβής από άλλον εξουσιοδοτημένο διαχειριστή. Η αξιολόγηση γίνεται με τον συνεργάτη πριν αποκτηθεί απευθείας επικοινωνία. Δεν απαιτείται να έχει ήδη πληρωθεί ο συνεργάτης για την αποκάλυψη.
- Επωνυμία, όνομα, τηλέφωνο, email, website, ακριβής τοποθεσία, ελεύθερα κείμενα και συνημμένα δεν αποστέλλονται στον browser του αξιολογητή πριν την αποδοχή. Ίδιος κανόνας σε λίστες, follow-ups, στατιστικά, CSV, επεξεργασία και downloads.
- Απόρριψη/συμπληρώσεις πριν την αποδοχή απαιτούν αιτιολογία. Μετά δεν επιτρέπεται επαναφορά σε pending ή απόρριψη. Lost δεν διαγράφει τις αμοιβές. Δεν παρέχεται μονομερής ακύρωση ήδη καταχωρισμένων προμηθειών· διαφωνίες καταγράφονται στο ιστορικό.
- Οι αμοιβές πάνελ παραμένουν €0,60 ανά πραγματικά αγορασμένο και πληρωμένο πάνελ. Η προμήθεια υπηρεσιών διατηρεί τον υφιστάμενο κανόνα πραγματικής είσπραξης.
- Επανυποβολή γίνεται στο ίδιο lead από τον δημιουργό. Έλεγχος διπλοτύπων κρατά και απορριφθείσες/χαμένες εγγραφές. Τα τρία είδη αγοράς πάνελ είναι μία οικογένεια για τον έλεγχο, ενώ υπηρεσίες και αγορά πάνελ μπορούν να είναι διαφορετικές ευκαιρίες. Ο έλεγχος αντιστοιχίζει εταιρεία/email, δεν αποδεικνύει ταυτότητα επιχειρήσεων με διαφορετικά ονόματα ή emails. Μελλοντικά διαφορετικά έργα στην ίδια εταιρεία χρειάζονται επέκταση του κανόνα.
- Προστίθεται πίνακας `lead_protection_events` και δύο πεδία `contacts_released_at/by`. Η ενημέρωση γίνεται αυτόματα στο πρώτο άνοιγμα των leads. Δεν χρειάζεται setup ή αλλαγή config. Παλαιές ήδη εγκεκριμένες αμοιβές παραμένουν ως έχουν. Παλαιά εκτεθειμένα στοιχεία δεν μπορούν να ανακληθούν.
- Ο συνεργάτης κατεβάζει αποδεικτικό HTML με καταχωρητή, ημερομηνίες, προμήθειες και στιγμιότυπα γεγονότων. Οι SHA-256 επιτρέπουν σύγκριση αντιγράφων, δεν είναι ανεξάρτητη χρονοσήμανση ή ψηφιακή υπογραφή.
- Η προστασία εφαρμόζεται στο CRM. Πρόσβαση Plesk/βάσης ή αλλαγή κώδικα μπορεί να την παρακάμψει. Δεν εγγυάται πραγματική τραπεζική πληρωμή, αποτροπή εξωσυστημικής επικοινωνίας ή διατήρηση πρόσβασης μετά την απενεργοποίηση λογαριασμού. Κρατήστε προσωπικά αντίγραφα.

### Εγκατάσταση ενημέρωσης

Πάρτε backup αρχείων και βάσης. Ανεβάστε το UPDATE v15 απευθείας στο υπάρχον `/crm` και εξαγάγετέ το με αντικατάσταση. Μην διαγράψετε το CRM, μην τρέξετε setup και μην αντικαταστήσετε το config. Το πακέτο δεν περιέχει config, storage, assets ή δεύτερο φάκελο crm. Περιλαμβάνει τις αλλαγές v13/v14 που απαιτούνται για την τρέχουσα ροή.
