<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$user = academy_require_login();
require_once __DIR__ . '/courses.php';
require_once __DIR__ . '/visual-guides.php';
require_once __DIR__ . '/learning-presentation-v38.php';
require_once __DIR__ . '/sales-terms.php';$readingTerms=[];

function academy_query(string $key): string
{
    if (!isset($_GET[$key])) return '';
    if (!is_string($_GET[$key])) { http_response_code(400); exit('Μη έγκυρο αίτημα.'); }
    return $_GET[$key];
}

$view = academy_query('view');
$lessonId = academy_query('lesson');
$pillarId = academy_query('pillar');
$resultId = academy_query('result');
$catalog = academy_catalog();
$lessons = academy_lessons();
if (!in_array($view, ['', 'team'], true) || ($lessonId !== '' && !isset($lessons[$lessonId]))
    || ($pillarId !== '' && !isset($catalog[$pillarId])) || ($view === 'team' && $lessonId !== '')) {
    http_response_code(404); exit('Η εκπαιδευτική σελίδα δεν βρέθηκε.');
}
if ($view === 'team' && !can_view_academy_team($user)) {
    http_response_code(403); exit('Δεν έχετε πρόσβαση στην πρόοδο της ομάδας.');
}
if ($lessonId !== '' && ($lessons[$lessonId]['type'] ?? '') === 'lab') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { http_response_code(400); exit('Χρησιμοποίησε το CRM Lab για την πρακτική αξιολόγηση.'); }
    redirect_to('practice.php?lesson='.rawurlencode($lessonId));
}

$error = null;
$available = true;
try { ensure_academy_schema(); }
catch (Throwable $exception) {
    error_log('Academy schema unavailable: ' . $exception->getMessage());
    http_response_code(503);
    $available = false;
    $error = 'Η Academy δεν μπόρεσε να ετοιμάσει την αποθήκευση προόδου. Ο διαχειριστής χρειάζεται να ελέγξει τη βάση και τα δικαιώματα δημιουργίας πινάκων. Δεν χρειάζεται νέο setup.';
}

if ($available && $lessonId !== '' && ($missing=academy_missing_prerequisites($user,$lessonId))) {
    http_response_code(403);render_header('Το επόμενο βήμα σου',$user);
    echo '<section class="ac-panel ac-lesson"><h1>Πρώτα ολοκλήρωσε το προηγούμενο μάθημα</h1><p>Η εκπαίδευση προχωρά σταδιακά. Τα quiz έχουν έως δύο προσπάθειες και ξεχωριστό τελικό βαθμό ανά μάθημα. Η καθοδηγούμενη εξάσκηση παραμένει διαθέσιμη.</p><ul>';
    foreach($missing as $required)echo '<li><a href="'.e(academy_url('index.php?lesson='.$required)).'">'.e(academy_lesson($required)['title']).'</a></li>';
    echo '</ul><a class="school-button" href="'.e(academy_url()).'">Πίσω στα μαθήματα</a></section>';render_footer();exit;
}
if ($available && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    try {
        if($view!== 'team' && $lessonId!=='' && ($_POST['action']??null)==='test_retake'){
            academy_test_retake($user,$lessonId);
            unset($_SESSION['academy_forms'][$lessonId.':'.$lessons[$lessonId]['version']]);
            redirect_to('index.php?lesson='.rawurlencode($lessonId).'#quiz-form');
        }
        if ($view === 'team' || $lessonId === '' || ($_POST['action'] ?? null) !== 'submit_quiz') throw new InvalidArgumentException('Μη έγκυρη ενέργεια.');
        academy_validate_form($lessonId, $_POST['version'] ?? null, $_POST['submission_key'] ?? null);
        if (!is_array($_POST['answers'] ?? null)) throw new InvalidArgumentException('Απάντησε στις ερωτήσεις πριν υποβάλεις το τεστ.');
        $resultId = academy_record_attempt($user, $lessonId, $_POST['answers'], $_POST['submission_key']);
        unset($_SESSION['academy_forms'][$lessonId . ':' . $lessons[$lessonId]['version']]);
        // PRG prevents refresh/back from submitting another attempt. No score from the client is used.
        redirect_to('index.php?lesson=' . rawurlencode($lessonId) . '&result=' . rawurlencode($resultId) . '#quiz-result');
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Academy attempt failed: ' . $exception->getMessage());
        http_response_code(503);
        $error = 'Η προσπάθεια δεν αποθηκεύτηκε. Δοκίμασε ξανά· η προηγούμενη πρόοδός σου παραμένει.';
    }
}
$lesson = $lessonId !== '' ? $lessons[$lessonId] : null;
$visualGuides = $lesson ? academy_visual_guides($lessonId) : [];
$progress = $team = [];
$result = null;
try {
    if ($available) {
        $progress = academy_my_progress($user);
        if ($lesson && $resultId !== '') $result = academy_my_result($user, $resultId, $lessonId);
        if ($view === 'team') $team = academy_team($user);
    }
} catch (Throwable $exception) {
    error_log('Academy progress unavailable: ' . $exception->getMessage());
    http_response_code(503);
    $available = false;
    $error = 'Η πρόοδος δεν είναι προσωρινά διαθέσιμη. Δοκίμασε ξανά ή ενημέρωσε τον διαχειριστή.';
}
$attemptState = $lesson && $available ? academy_attempt_state($user,$lessonId) : null;
if ($lesson && !$result && $attemptState && $attemptState['first']) $result=academy_my_result($user,$attemptState['first']['id'],$lessonId);
$summary = academy_summary($progress);
if ($resultId !== '' && !$result && $available) { http_response_code(404); $error = 'Το αποτέλεσμα δεν βρέθηκε για τον λογαριασμό σου και την τρέχουσα έκδοση του μαθήματος.'; }
render_header('Melas Sales Academy', $user);
?>
<link rel="stylesheet" href="<?= e(academy_url('reader-v33.css')) ?>">
<div class="academy <?= $lesson ? 'ac-theory' : '' ?>" data-academy data-ac-pillar="<?= e($lesson['pillar'] ?? '') ?>">
  <?php if($view===''&&$lessonId===''&&academy_crm_enabled()): ?><details class="school-onboarding-note"><summary>Πώς συνδέονται η εκπαίδευση και η πρόσβασή μου;</summary><p>Ξεκινάς εδώ, χωρίς να απαιτούνται υπογραφές. Ολοκλήρωσε μαθήματα, quizzes, CRM Labs, τελική εξέταση, εικονική πρακτική και εκπαιδευτική αξιολόγηση. Μετά ακολουθούν τα έγγραφα και οι υπογραφές στην προσωπική σελίδα υποδοχής και, τέλος, ξεχωριστή έγκριση για το πραγματικό CRM. Οι υπάρχουσες προσβάσεις και εξαιρέσεις δεν αλλάζουν.</p><a class="ac-button ac-secondary" href="/crm/onboarding.php">Η πρόσβασή μου / επόμενα βήματα →</a></details><?php endif; ?>
  <?php if($available&&$view!=='team'&&($pillarId==='distillogic'||($lesson['additional_curriculum']??0)===37)): ?><p class="enterprise-update-note"><?= academy_v37_supplementary($user)?'Τα μαθήματα 31–60 είναι επιπλέον εκπαίδευση για εσένα. Η ήδη επιτυχής τελική εξέταση και ο υπολογισμός του υπάρχοντος συνολικού βαθμού διατηρούνται· δεν χρειάζεται νέα τελική προσπάθεια.':'Στον πυλώνα Distillogic προστέθηκαν τα κεφάλαια 31–60. Κάθε νέο μάθημα περιλαμβάνει πλήρη θεωρία και quiz πέντε ερωτήσεων. Η επίσημη τελική εξέταση παραμένει μία.' ?></p><?php endif; ?>
  <?php if ($error): ?><div class="ac-notice ac-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if (!$available): ?>
    <section class="ac-panel"><h1>Η Academy δεν είναι προσωρινά διαθέσιμη</h1><p>Η εκπαιδευτική πλατφόρμα λειτουργεί ανεξάρτητα από τα CRM.</p></section>
  <?php elseif ($view === 'team'): ?>
    <header class="ac-section-head"><div><span class="ac-kicker">ΕΙΚΟΝΑ ΕΚΠΑΙΔΕΥΣΗΣ</span><h1>Η εξέλιξη της ομάδας</h1><p>Ενεργοί λογαριασμοί · πρόοδος στα τρέχοντα μαθήματα · χωρίς στοιχεία πελατών.</p></div><button class="ac-button ac-secondary" type="button" data-ac-print>Εκτύπωση αναφοράς</button></header>
    <?php $finished = count(array_filter($team, static fn(array $member): bool => $member['summary']['completed'] === $member['summary']['total'])); ?>
    <div class="ac-metrics"><div><strong><?= count($team) ?></strong><span>ενεργοί λογαριασμοί</span></div><div><strong><?= $finished ?></strong><span>ολοκλήρωσαν όλα τα τεστ</span></div><div><strong><?= count($lessons) ?></strong><span>μαθήματα σε <?= count($catalog) ?> πυλώνες</span></div></div>
    <section class="ac-panel"><div class="ac-section-head"><h2>Ανά εργαζόμενο</h2><label class="ac-search">Αναζήτηση ονόματος / email<input type="search" data-ac-search placeholder="Βρες έναν εργαζόμενο…"></label></div>
      <div class="ac-table-wrap"><table class="ac-table"><caption class="ac-sr-only">Πρόοδος ενεργών λογαριασμών ανά εκπαιδευτικό πυλώνα</caption><thead><tr><th scope="col">Εργαζόμενος</th><?php foreach ($catalog as $group): ?><th scope="col"><?= e($group['title']) ?></th><?php endforeach; ?><th scope="col">Σύνολο</th></tr></thead><tbody>
      <?php foreach ($team as $member): ?><tr data-ac-searchable="<?= e($member['name'] . ' ' . $member['email']) ?>"><th scope="row"><strong><?= e($member['name']) ?></strong><small><?= e($member['email']) ?></small></th><?php foreach (array_keys($catalog) as $key): $s = academy_summary($member['progress'], $key); ?><td><?= $s['completed'] ?> / <?= $s['total'] ?></td><?php endforeach; ?><td><strong><?= $member['summary']['percent'] ?>%</strong><progress max="<?= $member['summary']['total'] ?>" value="<?= $member['summary']['completed'] ?>" aria-label="Συνολική πρόοδος <?= e($member['name']) ?>"></progress><details><summary>Ανάλυση μαθημάτων</summary><ul class="ac-team-detail"><?php foreach ($lessons as $id => $item): $p = $member['progress'][$id] ?? null; ?><li><strong><?= e($item['title']) ?></strong><span><?= !$p ? 'Χωρίς τεστ' : (!empty($p['completed_at']) ? 'Ολοκληρώθηκε' : 'Σε εξάσκηση') ?><?= $p ? ' · πρώτος βαθμός ' . ($p['first_uncertain'] ? 'χρειάζεται έλεγχο' : (int)$p['first_score'] . '/' . (int)$p['first_question_count']) . ($p['retake_passed'] ? ' · ολοκλήρωση με εγκεκριμένη επανεξέταση' : ' · βαθμός μαθήματος '.($p['final_grade']??'—').'/100') . ' · ' . (int)$p['attempts'] . ' προσπάθειες' : '' ?></span><?php if ($p): ?><small>Τελευταίο τεστ: <?= e(format_datetime($p['last_attempt_at'])) ?><?= !empty($p['completed_at']) ? ' · Ολοκλήρωση: ' . e(format_datetime($p['completed_at'])) : '' ?></small><?php endif; ?></li><?php endforeach; ?></ul></details></td></tr><?php endforeach; ?>
      </tbody></table></div><p class="ac-empty" data-ac-empty hidden>Δεν βρέθηκε αντίστοιχος λογαριασμός.</p>
    </section><p class="ac-fineprint">Η αναφορά δείχνει τεστ κατανόησης, όχι ώρες εργασίας ή αξιολόγηση εμπορικής απόδοσης. Δεν αλλάζει ρόλους, πρόσβαση ή εξουσιοδότηση. Ανενεργοί λογαριασμοί δεν εμφανίζονται· η αποθηκευμένη πρόοδός τους διατηρείται.</p>
  <?php elseif ($lesson): $pillar = $catalog[$lesson['pillar']]; $lessonProgress = $progress[$lessonId] ?? null; $ids = array_keys($lessons); $position = array_search($lessonId, $ids, true); $following = $ids[$position + 1] ?? null; $previous = $ids[$position - 1] ?? null; $chapterCount = count($lesson['sections']); $pillarProgress = academy_summary($progress, $lesson['pillar']); ?>
    <div class="ac-breadcrumb"><a href="<?= e(academy_url('index.php?pillar=' . $lesson['pillar'])) ?>">← <?= e($pillar['title']) ?></a><span>Μάθημα <?= $position + 1 ?> από <?= count($lessons) ?></span></div>
    <div class="ac-learning-layout">
      <aside class="ac-syllabus ac-panel">
        <div class="ac-course-identity"><span class="ac-course-symbol" aria-hidden="true"><?= academy_icon($pillar['icon']) ?></span><div><span class="ac-kicker">Η ΔΙΑΔΡΟΜΗ ΣΟΥ</span><strong><?= e($pillar['title']) ?></strong></div></div>
        <div class="ac-course-progress"><span><?= $pillarProgress['completed'] ?> / <?= $pillarProgress['total'] ?> μαθήματα ολοκληρωμένα</span><progress max="<?= $pillarProgress['total'] ?>" value="<?= $pillarProgress['completed'] ?>" aria-label="Ολοκλήρωση μαθημάτων στον πυλώνα <?= e($pillar['title']) ?>"></progress></div>
        <details class="ac-lesson-outline" open data-ac-outline><summary>Σε αυτό το μάθημα <span><?= $chapterCount ?> ενότητες</span></summary><nav aria-label="Περιεχόμενα μαθήματος"><ol>
          <?php foreach ($lesson['sections'] as $chapterIndex => [$heading]): ?><li><a href="#chapter-<?= $chapterIndex + 1 ?>" data-ac-jump><span><?= e(academy_heading_display($heading)) ?></span></a></li><?php endforeach; ?>
          <li><a href="#lesson-scenario" data-ac-jump><span class="ac-outline-number" aria-hidden="true">↗</span><span>Πρακτικό παράδειγμα</span></a></li>
          <?php if ($visualGuides): ?><li><a href="#visual-guide" data-ac-jump><span class="ac-outline-number" aria-hidden="true">◉</span><span>Οδηγίες με εικόνες</span></a></li><?php endif; ?>
          <?php if (!empty($lesson['reference'])): ?><li><a href="#lesson-reference" data-ac-jump><span class="ac-outline-number" aria-hidden="true">≡</span><span>Γρήγορη αναφορά</span></a></li><?php endif; ?>
          <?php if ($lessonId === 'me-commissions' || $lessonId === 'crm-entry'): ?><li><a href="#lesson-calculator" data-ac-jump><span class="ac-outline-number" aria-hidden="true">+</span><span>Δοκίμασε τους αριθμούς</span></a></li><?php endif; ?>
          <li><a href="#quiz" data-ac-jump><span class="ac-outline-number" aria-hidden="true">✓</span><span>Έλεγχος κατανόησης</span></a></li>
        </ol></nav></details>
        <div class="ac-reading-position" data-ac-reading-position hidden><div><span>Θέση ανάγνωσης</span><span data-ac-reading-percent>0%</span></div><progress max="100" value="0" data-ac-reading-bar aria-label="Θέση περιήγησης μέσα στο μάθημα, όχι βαθμολογία"></progress><small>Η ολοκλήρωση καταγράφεται μόνο από το τεστ.</small></div>
        <details class="ac-course-library"><summary>Όλα τα μαθήματα</summary><?php foreach ($catalog as $key => $group): ?><details <?= $key === $lesson['pillar'] ? 'open' : '' ?>><summary><?= e($group['title']) ?></summary><ol><?php foreach ($group['lessons'] as $id => $item): ?><li><a href="<?= e(academy_url('index.php?lesson=' . $id)) ?>" <?= $id === $lessonId ? 'aria-current="step"' : '' ?>><span class="ac-step-state" aria-label="<?= !empty($progress[$id]['completed_at']) ? 'Ολοκληρώθηκε' : 'Δεν ολοκληρώθηκε' ?>"><?= !empty($progress[$id]['completed_at']) ? '✓' : '○' ?></span><?= e($item['title']) ?></a></li><?php endforeach; ?></ol></details><?php endforeach; ?></details>
      </aside>
      <div class="ac-reading">
        <article class="ac-panel ac-lesson" data-ac-reading-article><header class="ac-lesson-cover"><div class="ac-cover-top"><span class="ac-kicker"><?= e($pillar['tag']) ?></span><span class="ac-theory-label">ΘΕΩΡΙΑ</span></div><h1><?= sales_terms_html($lesson['title'],$readingTerms) ?></h1><div class="ac-lesson-meta"><span>≈ <?= $lesson['minutes'] ?>′ μελέτη</span><span><?= $chapterCount ?> ενότητες</span><span><?= count($lesson['quiz']) ?> ερωτήσεις</span><span><?= !empty($lessonProgress['completed_at']) ? '✓ Ολοκληρώθηκε' : 'Διάβασε · εξασκήσου · δοκίμασε' ?></span></div><div class="ac-goal-card"><span class="ac-kicker">Ο ΣΤΟΧΟΣ ΤΟΥ ΜΑΘΗΜΑΤΟΣ</span><p class="ac-goal"><?= sales_terms_html($lesson['goal'],$readingTerms) ?></p></div><div class="ac-cover-actions"><a href="#chapter-1">Ξεκίνα την ανάγνωση <span aria-hidden="true">↓</span></a><a href="#quiz">Στο τεστ κατανόησης <span aria-hidden="true">↗</span></a></div></header>
        <?php foreach ($lesson['sections'] as $chapterIndex => [$heading, $paragraphs]): ?><section class="ac-chapter" id="chapter-<?= $chapterIndex + 1 ?>" data-ac-reading-section aria-labelledby="chapter-title-<?= $chapterIndex + 1 ?>"><header class="ac-chapter-heading"><h2 id="chapter-title-<?= $chapterIndex + 1 ?>"><?= sales_terms_html(academy_heading_display($heading),$readingTerms) ?></h2></header><div class="ac-chapter-copy"><?php foreach ($paragraphs as $paragraph) academy_render_theory_paragraph($paragraph,$readingTerms); ?></div></section><?php endforeach; ?>
        <section class="ac-scenario" id="lesson-scenario" data-ac-reading-section aria-labelledby="scenario-title"><div class="ac-scenario-heading"><span class="ac-scenario-symbol" aria-hidden="true">↗</span><div><span class="ac-kicker">ΣΤΗΝ ΠΡΑΞΗ</span><h2 id="scenario-title">Εσύ τι θα έκανες;</h2></div></div><p class="ac-scenario-prompt"><?= sales_terms_html($lesson['scenario'][0],$readingTerms) ?></p><details><summary>Δες μια ενδεικτική προσέγγιση</summary><p><?= sales_terms_html($lesson['scenario'][1],$readingTerms) ?></p></details></section>
        <?php if ($visualGuides): ?><section class="ac-visual-guide" id="visual-guide" data-ac-reading-section aria-labelledby="visual-guide-title"><header><span class="ac-kicker">ΔΕΣ ΤΗ ΔΙΑΔΡΟΜΗ</span><h2 id="visual-guide-title">Πού πατάω; Βήμα προς βήμα.</h2><p>Πραγματικές οθόνες της εφαρμογής σε δοκιμαστικό περιβάλλον, με πλασματικά στοιχεία. Πάτησε μια εικόνα για πλήρες μέγεθος. Οι διαθέσιμες επιλογές διαφέρουν ανά ρόλο.</p></header><?php foreach ($visualGuides as [$file, $title, $caption]): [$width, $height] = academy_guide_dimensions($file); ?><figure><figcaption><h3><?= sales_terms_html($title,$readingTerms) ?></h3><p><?= sales_terms_html($caption,$readingTerms) ?></p></figcaption><a href="<?= e(academy_url($file)) ?>" target="_blank" rel="noopener" aria-label="<?= e($title) ?> · άνοιγμα εικόνας πλήρους μεγέθους σε νέα καρτέλα"><img src="<?= e(academy_url($file)) ?>" alt="<?= e($title . '. ' . $caption) ?>" width="<?= $width ?>" height="<?= $height ?>" loading="lazy" decoding="async"><span>Μεγέθυνση εικόνας ↗</span></a></figure><?php endforeach; ?><p class="ac-fineprint">Οι εικόνες δεν είναι ζωντανές φόρμες. Για εξάσκηση χωρίς πραγματικές καταχωρίσεις χρησιμοποίησε το CRM Lab.</p></section><?php endif; ?>
        <?php if (!empty($lesson['reference'])): ?><details class="ac-reference" id="lesson-reference" data-ac-reading-section><summary>Γρήγορη αναφορά · βασικά σημεία</summary><p>Τα αρχικά σημεία του μαθήματος παραμένουν συγκεντρωμένα εδώ για επανάληψη και καθημερινή αναφορά.</p><?php foreach ($lesson['reference'] as [$heading, $paragraphs]): ?><section><h3><?= sales_terms_html($heading,$readingTerms) ?></h3><?php foreach ($paragraphs as $paragraph): ?><p><?= sales_terms_html($paragraph,$readingTerms) ?></p><?php endforeach; ?></section><?php endforeach; ?></details><?php endif; ?>
        <?php if ($lessonId === 'me-commissions' || $lessonId === 'crm-entry'): ?>
        <section class="ac-lab" id="lesson-calculator" data-ac-reading-section data-ac-lab><span class="ac-kicker">ΑΣΦΑΛΗΣ ΕΞΑΣΚΗΣΗ</span><h2>Δοκίμασε τους αριθμούς</h2><p>Μόνο εκπαιδευτικά δεδομένα. Δεν δημιουργείται lead, συναλλαγή ή προμήθεια.</p><div class="ac-lab-fields"><label>Πληρωμένα πάνελ<input type="number" min="0" max="1000000" step="1" value="2000" data-ac-quantity></label><label>Τιμή ανά πάνελ (€)<input type="number" min="0" max="1000000" step="0.01" value="20" data-ac-price></label></div><label class="ac-choice ac-inline"><input type="checkbox" data-ac-approved checked><span>Το qualified lead έχει εγκριθεί (€80)</span></label><output class="ac-lab-output" data-ac-output aria-live="polite"></output><p class="ac-fineprint">Οι αριθμοί είναι υποθετικοί. Στην πραγματική εργασία η ζητούμενη τιμή δεν είναι απόδειξη αγοράς ή πληρωμής.</p><noscript>Ο υπολογιστής χρειάζεται JavaScript. Τύποι: αξία = ποσότητα × τιμή, προμήθεια πάνελ = πληρωμένη ποσότητα × €0,60, συν €80 μόνο αν έχει εγκριθεί το lead.</noscript></section>
        <?php endif; ?>
        <?php if(!empty($lesson['source_note'])): ?><p class="ac-source"><?= e($lesson['source_note']) ?></p><?php endif; ?>
<?php foreach(($lesson['sources']??[]) as [$sourceTitle,$sourceUrl]): ?><p class="ac-source"><a href="<?= e($sourceUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e($sourceTitle) ?> ↗</a></p><?php endforeach; ?>
<p class="ac-source"><?= e($pillar['source']) ?> · Έκδοση μαθήματος <?= $lesson['version'] ?>.</p></article>
        <section class="ac-panel ac-quiz" id="quiz" data-ac-reading-section><div class="ac-quiz-heading"><span class="ac-quiz-symbol" aria-hidden="true"><?= academy_icon('check') ?></span><div><span class="ac-kicker">ΕΛΕΓΧΟΣ ΚΑΤΑΝΟΗΣΗΣ</span><h2>Ας το κάνουμε πράξη</h2></div><span class="ac-quiz-count"><?= count($lesson['quiz']) ?> ερωτήσεις</span></div><p>Το μάθημα ολοκληρώνεται με <strong>τουλάχιστον 80% σωστές απαντήσεις</strong>. Επιτυχία στην πρώτη κρατά τον πρώτο βαθμό. Αν περάσεις στη δεύτερη, μετρά max(μέσος όρος δύο, 80%). Αν αποτύχουν και οι δύο, το μάθημα δεν ολοκληρώνεται.</p><p class="ac-attempt-policy" role="status"><?= e(academy_attempt_notice($attemptState)) ?></p>
          <?php if ($result): $resultAnswers = json_decode($result['answers_json'], true); ?>
          <div class="ac-result <?= $result['passed'] ? 'is-pass' : 'is-retry' ?>" id="quiz-result" role="status" tabindex="-1"><strong><?= $result['passed'] ? 'Επιτυχής καταγεγραμμένη προσπάθεια' : 'Καταγεγραμμένη προσπάθεια' ?> · <?= (int)$result['score'] ?>/<?= (int)$result['question_count'] ?></strong><p><?= $result['passed'] ? 'Το αποτέλεσμα παραμένει στο ιστορικό. Ο τελικός βαθμός μαθήματος εμφανίζεται στην καρτέλα «Βαθμολογία».' : 'Διάβασε τις εξηγήσεις και ξαναμελέτησε. Δες παρακάτω αν απομένει δεύτερη προσπάθεια. Μετά την εξάντληση χρειάζεται άδεια διαχειριστή.' ?></p><ol><?php foreach ($result['questions'] as $i => $question): $display=academy_quiz_presentation($lessonId,$i,$question); ?><li><?php if($display['context']!==''): ?><p><?= e($display['context']) ?></p><?php endif; ?><strong><?= e($display['ask']) ?></strong><p>Η απάντησή σου: <?= e($display['options'][$resultAnswers[$i]] ?? '—') ?> · <?= ($resultAnswers[$i] ?? null) === $question[2] ? 'Σωστή' : 'Χρειάζεται επανάληψη' ?></p><p class="quiz-answer"><strong>Σωστή απάντηση:</strong> <?= e($display['options'][$question[2]]) ?></p><p><strong>Γιατί:</strong> <?= e($display['why']) ?></p></li><?php endforeach; ?></ol></div>
          <?php endif; ?>
          <?php if(academy_can_test_retake($user)&&$attemptState['count']&&!$attemptState['grant']): ?><form method="post" action="<?= e(academy_url('index.php?lesson='.$lessonId)) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="test_retake"><p>Προσωπικός έλεγχος Sophianos: μπορείς να επαναλάβεις το τεστ. Ο πρώτος βαθμός και το ιστορικό παραμένουν.</p><button class="ac-button" type="submit">Νέα επανεξέταση δοκιμής</button></form><?php endif; ?>
          <?php if($attemptState['allowed']): ?><form id="quiz-form" method="post" action="<?= e(academy_url('index.php?lesson=' . $lessonId . '#quiz')) ?>" class="ac-quiz-form"><?= csrf_field() ?><input type="hidden" name="action" value="submit_quiz"><input type="hidden" name="version" value="<?= $lesson['version'] ?>"><input type="hidden" name="submission_key" value="<?= e(academy_form_key($lessonId)) ?>">
          <?php foreach ($lesson['quiz'] as $i => $question): $display=academy_quiz_presentation($lessonId,$i,$question); ?><fieldset><legend><span><?= $i + 1 ?></span>Ερώτηση <?= $i+1 ?> από <?= count($lesson['quiz']) ?></legend><?php if($display['context']!==''): ?><div class="school-quiz-scenario"><span>ΤΟ ΠΕΡΙΣΤΑΤΙΚΟ</span><p><?= e($display['context']) ?></p></div><?php endif; ?><p class="school-quiz-ask" id="quiz-question-<?= $i ?>"><?= e($display['ask']) ?></p><div role="group" aria-labelledby="quiz-question-<?= $i ?>"><?php foreach ($display['options'] as $option => $text): ?><label class="ac-choice"><input type="radio" name="answers[<?= $i ?>]" value="<?= $option ?>" required><span><?= e($text) ?></span></label><?php endforeach; ?></div></fieldset><?php endforeach; ?>
          <button class="ac-button" type="submit"><?= $attemptState['remaining']>0 ? 'Οριστική υποβολή · προσπάθεια '.(3-$attemptState['remaining']).' / 2' : 'Υποβολή εγκεκριμένης επανεξέτασης' ?> <?= academy_icon('arrow') ?></button></form><?php endif; ?>
        </section>
        <nav class="ac-bottom-nav" aria-label="Πλοήγηση μαθημάτων"><?php if ($previous): ?><a class="ac-button ac-secondary" href="<?= e(academy_url('index.php?lesson=' . $previous)) ?>">← Προηγούμενο μάθημα</a><?php else: ?><a class="ac-button ac-secondary" href="<?= e(academy_url('index.php')) ?>">Όλα τα μαθήματα</a><?php endif; ?><?php if ($following): ?><a class="ac-button" href="<?= e(academy_url('index.php?lesson=' . $following)) ?>">Επόμενο μάθημα <?= academy_icon('arrow') ?></a><?php endif; ?></nav>
      </div>
    </div>
  <?php else: ?>
    <section class="ac-hero"><div class="ac-hero-copy"><span class="ac-kicker">Η ΓΝΩΣΗ ΓΙΝΕΤΑΙ ΕΥΚΑΙΡΙΑ</span><h1>Μάθε το αντικείμενο.<br>Κάνε το επόμενο βήμα.</h1><p>Τέσσερις πυλώνες. Μία κοινή βάση γνώσης.<br>Για ουσιαστικές συζητήσεις και σίγουρη χρήση των εργαλείων σου.</p><a class="ac-button" href="<?= e(academy_url('index.php?lesson=' . ($summary['next'] ?? array_key_first($lessons)))) ?>"><?= $summary['completed'] === $summary['total'] ? 'Επανάληψη μαθημάτων' : ($summary['attempted'] ? 'Συνέχισε την εκπαίδευση' : 'Ξεκίνα την εκπαίδευση') ?> <?= academy_icon('arrow') ?></a></div><div class="ac-hero-progress"><span class="ac-orbit" aria-hidden="true"><?= academy_icon('book') ?></span><span class="ac-kicker">Η ΠΡΟΟΔΟΣ ΣΟΥ</span><strong><?= $summary['percent'] ?><small>%</small></strong><progress value="<?= $summary['completed'] ?>" max="<?= $summary['total'] ?>" aria-label="Η συνολική πρόοδός σου"></progress><p><?= $summary['completed'] ?> από <?= $summary['total'] ?> μαθήματα ολοκληρωμένα</p></div></section>
    <div class="ac-metrics"><div><strong><?= str_pad((string)count($catalog), 2, '0', STR_PAD_LEFT) ?></strong><span>εκπαιδευτικοί πυλώνες</span></div><div><strong><?= count($lessons) ?></strong><span>μαθήματα θεωρίας & πρακτικής</span></div><div><strong><?= array_sum(array_map(static fn($item)=>count($item['quiz']),$lessons)) ?></strong><span>ερωτήσεις θεωρίας</span></div><div><strong>≈ <?= $summary['minutes'] ?>′</strong><span>ενδεικτική συνολική μελέτη</span></div></div>
    <?php if ($summary['completed'] === $summary['total']): ?><section class="ac-completion"><span><?= academy_icon('check') ?></span><div><h2>Ολοκλήρωσες την τρέχουσα διαδρομή!</h2><p>Όλα τα τεστ ολοκληρώθηκαν επιτυχώς. Συνέχισε με πρακτική καθοδήγηση από την ομάδα σου. Αυτό δεν αποτελεί εξωτερική πιστοποίηση ή αυτόματη εξουσιοδότηση.</p></div></section><?php endif; ?>
    <section aria-labelledby="ac-pillars-heading"><div class="ac-section-head"><div><span class="ac-kicker">ΤΟ ΠΡΟΓΡΑΜΜΑ ΣΟΥ</span><h2 id="ac-pillars-heading">Τέσσερις πυλώνες, μία διαδρομή.</h2></div><p>Μελέτη με τον δικό σου ρυθμό.</p></div><div class="ac-pillars"><?php foreach ($catalog as $key => $pillar): $s = academy_summary($progress, $key); ?><a class="ac-pillar ac-pillar-<?= e($key) ?>" href="<?= e(academy_url('index.php?pillar=' . $key . '#lessons')) ?>"><span class="ac-pillar-icon"><?= academy_icon($pillar['icon']) ?></span><span class="ac-kicker"><?= e($pillar['tag']) ?></span><h3><?= e($pillar['title']) ?></h3><p><?= e($pillar['description']) ?></p><div class="ac-pillar-bottom"><span><?= $s['completed'] ?>/<?= $s['total'] ?> μαθήματα · ≈ <?= $s['minutes'] ?>′</span><?= academy_icon('arrow') ?></div><progress value="<?= $s['completed'] ?>" max="<?= $s['total'] ?>" aria-label="Πρόοδος <?= e($pillar['title']) ?>"></progress></a><?php endforeach; ?></div></section>
    <section class="ac-panel ac-curriculum" id="lessons"><div class="ac-section-head"><div><span class="ac-kicker">ΜΙΚΡΑ ΒΗΜΑΤΑ, ΣΤΑΘΕΡΗ ΕΞΕΛΙΞΗ</span><h2><?= $pillarId ? e($catalog[$pillarId]['title']) : 'Όλα τα μαθήματα' ?></h2></div><label class="ac-search">Αναζήτηση μαθήματος<input type="search" data-ac-search placeholder="π.χ. πάνελ, προμήθειες, CRM…"></label></div><nav class="ac-filters" aria-label="Φίλτρο πυλώνα"><a href="<?= e(academy_url('index.php#lessons')) ?>" <?= !$pillarId ? 'aria-current="true"' : '' ?>>Όλα</a><?php foreach ($catalog as $key => $pillar): ?><a href="<?= e(academy_url('index.php?pillar=' . $key . '#lessons')) ?>" <?= $pillarId === $key ? 'aria-current="true"' : '' ?>><?= e($pillar['title']) ?></a><?php endforeach; ?></nav>
    <ol class="ac-lessons"><?php $number = 0; foreach ($lessons as $id => $item): $number++; if ($pillarId && $item['pillar'] !== $pillarId) continue; $p = $progress[$id] ?? null; $blocked=!academy_is_admin($user)&&empty($p['completed_at'])&&count(array_filter(academy_prerequisites($id),static fn($needed)=>empty($progress[$needed]['completed_at'])))>0; ?><li data-ac-searchable="<?= e($item['title'] . ' ' . $item['goal'] . ' ' . $item['pillar_title']) ?>"><a href="<?= e(academy_url('index.php?lesson=' . $id)) ?>"><span class="ac-lesson-number <?= !empty($p['completed_at']) ? 'is-complete' : '' ?>"><?= !empty($p['completed_at']) ? academy_icon('check') : str_pad((string)$number, 2, '0', STR_PAD_LEFT) ?></span><span class="ac-lesson-label"><small><?= e($item['pillar_title']) ?> · ≈ <?= $item['minutes'] ?>′</small><strong><?= e($item['title']) ?></strong><span><?= e($item['goal']) ?></span></span><span class="ac-lesson-state <?= $blocked?'is-locked':'' ?>"><?= !empty($p['completed_at']) ? 'Ολοκληρώθηκε' : ($blocked?'Προαπαιτούμενα':($p ? 'Συνέχισε' : 'Έναρξη')) ?><?= academy_icon('arrow') ?></span></a></li><?php endforeach; ?></ol><p class="ac-empty" data-ac-empty hidden>Δεν βρέθηκε μάθημα με αυτόν τον όρο. Δοκίμασε άλλη λέξη ή επίλεξε «Όλα».</p></section>
    <?php if (in_array($pillarId, ['crm','crm-distillogic'], true)): ?><section class="ac-panel ac-shared-labs"><h2>Κοινή βάση για τα δύο CRM</h2><p>Αυτές οι δύο ασκήσεις αφορούν και τις δύο εταιρείες. Εμφανίζονται στον κατάλογο του Melas CRM, αλλά τις ολοκληρώνεις μία φορά και η ίδια πρόοδος χρησιμοποιείται και στις δύο τελικές αποστολές.</p><a class="ac-button ac-secondary" href="<?= e(academy_url('practice.php?lesson=lab-navigation')) ?>">Ο σωστός χώρος, ο σωστός ιδιοκτήτης →</a> <a class="ac-button ac-secondary" href="<?= e(academy_url('practice.php?lesson=lab-privacy')) ?>">Δικαιώματα και ασφαλής καθημερινή χρήση →</a></section><?php endif; ?>
    <section class="ac-panel"><h2>Από τη θεωρία στην πράξη</h2><p>Στο CRM Lab λύνεις 14 πρακτικές ασκήσεις για τις δύο εταιρείες: διαβάζεις συνομιλίες, συμπληρώνεις καρτέλες και παίρνεις εξηγήσεις για κάθε απάντηση. Μετά την τελική εξέταση ακολουθούν αυτόματες εικονικές κλήσεις: επίλεξε απαντήσεις και συμπλήρωσε την εκπαιδευτική καρτέλα, χωρίς πραγματικούς πελάτες. Η τελική έγκριση παραμένει στη διοίκηση.</p><a class="ac-button" href="<?= e(academy_url('practice.php')) ?>">CRM Lab · Άνοιξε το demo γραφείο →</a> <a class="ac-button" href="<?= e(academy_url('assessment.php')) ?>">Τελική αξιολόγηση & πρακτική →</a></section><section class="ac-how"><h2>Πώς λειτουργεί</h2><div><p><strong>01 / Μελέτη</strong>Διάβασε το μάθημα και σκέψου το πρακτικό σενάριο.</p><p><strong>02 / Κατανόηση</strong>Στη θεωρία χρειάζεσαι ≥80%. Στο CRM Lab χρειάζεσαι ≥85% και όλες τις κρίσιμες αποφάσεις σωστές. Έχεις έως δύο προσπάθειες στα quiz θεωρίας και μία στα βαθμολογούμενα CRM Labs· η καθοδηγούμενη εξάσκηση είναι ελεύθερη.</p><p><strong>03 / Εφαρμογή</strong>Η πρόοδος αποθηκεύεται στον λογαριασμό σου, και συνεχίζεις από οποιαδήποτε συσκευή.</p></div></section>
    <p class="ac-fineprint">Οι χρόνοι είναι ενδεικτικοί, όχι καταγραφή χρόνου εργασίας. Εσύ βλέπεις τη δική σου πρόοδο· ο Μελάς και ο εξουσιοδοτημένος διαχειριστής βλέπουν την πρόοδο της ομάδας. Δεν εισάγουμε στοιχεία πραγματικών πελατών στα εκπαιδευτικά παραδείγματα.</p>
  <?php endif; ?>
</div>
<script src="<?= e(academy_url('courses.js?v=33')) ?>" defer></script>
<script src="<?= e(academy_url('reader-v33.js')) ?>" defer></script>
<?php render_footer(); ?>
