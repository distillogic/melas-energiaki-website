<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$user = require_login();
// Keep old bookmarks usable without presenting an obsolete parallel curriculum.
header('Location: /academy/',true,303);
exit;
require_once __DIR__ . '/academy-workflow.php';

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

$error = null;
$available = true;
try { ensure_academy_schema(); }
catch (Throwable $exception) {
    error_log('Academy schema unavailable: ' . $exception->getMessage());
    http_response_code(503);
    $available = false;
    $error = 'Η Academy δεν μπόρεσε να ετοιμάσει την αποθήκευση προόδου. Ο διαχειριστής χρειάζεται να ελέγξει τη βάση και τα δικαιώματα δημιουργίας πινάκων. Δεν χρειάζεται νέο setup.';
}

if ($available && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    try {
        if ($view === 'team' || $lessonId === '' || ($_POST['action'] ?? null) !== 'submit_quiz') throw new InvalidArgumentException('Μη έγκυρη ενέργεια.');
        academy_validate_form($lessonId, $_POST['version'] ?? null, $_POST['submission_key'] ?? null);
        if (!is_array($_POST['answers'] ?? null)) throw new InvalidArgumentException('Απάντησε στις ερωτήσεις πριν υποβάλεις το τεστ.');
        $resultId = academy_record_attempt($user, $lessonId, $_POST['answers'], $_POST['submission_key']);
        unset($_SESSION['academy_forms'][$lessonId . ':' . $lessons[$lessonId]['version']]);
        // PRG prevents refresh/back from submitting another attempt. No score from the client is used.
        redirect_to('academy.php?lesson=' . rawurlencode($lessonId) . '&result=' . rawurlencode($resultId) . '#quiz-result');
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
$summary = academy_summary($progress);
if ($resultId !== '' && !$result && $available) { http_response_code(404); $error = 'Το αποτέλεσμα δεν βρέθηκε για τον λογαριασμό σου και την τρέχουσα έκδοση του μαθήματος.'; }
render_header('Melas Sales Academy', $user);
?>
<div class="academy" data-academy>
  <div class="ac-topline"><a class="ac-wordmark" href="<?= e(crm_url('academy.php')) ?>"><?= academy_icon('book') ?><span>MELAS <strong>SALES ACADEMY</strong></span></a><span class="ac-edition">LEARN · PRACTICE · GROW</span></div>
  <nav class="ac-tabs" aria-label="Πλοήγηση Academy">
    <a href="<?= e(crm_url('academy.php')) ?>" <?= $view !== 'team' ? 'aria-current="page"' : '' ?>>Η εκπαίδευσή μου</a>
    <?php if (can_view_academy_team($user)): ?><a href="<?= e(crm_url('academy.php?view=team')) ?>" <?= $view === 'team' ? 'aria-current="page"' : '' ?>>Πρόοδος ομάδας</a><?php endif; ?>
    <a href="<?= e(crm_url('sales-guide.php')) ?>">Σύντομος οδηγός ↗</a>
  </nav>
  <?php if ($error): ?><div class="ac-notice ac-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if (!$available): ?>
    <section class="ac-panel"><h1>Η Academy δεν είναι προσωρινά διαθέσιμη</h1><p>Τα υπόλοιπα εργαλεία CRM δεν αλλάζουν από αυτή την ενημέρωση.</p></section>
  <?php elseif ($view === 'team'): ?>
    <header class="ac-section-head"><div><span class="ac-kicker">ΕΙΚΟΝΑ ΕΚΠΑΙΔΕΥΣΗΣ</span><h1>Η εξέλιξη της ομάδας</h1><p>Ενεργοί λογαριασμοί · πρόοδος στα τρέχοντα μαθήματα · χωρίς στοιχεία πελατών.</p></div><button class="ac-button ac-secondary" type="button" data-ac-print>Εκτύπωση αναφοράς</button></header>
    <?php $finished = count(array_filter($team, static fn(array $member): bool => $member['summary']['completed'] === $member['summary']['total'])); ?>
    <div class="ac-metrics"><div><strong><?= count($team) ?></strong><span>ενεργοί λογαριασμοί</span></div><div><strong><?= $finished ?></strong><span>ολοκλήρωσαν όλα τα τεστ</span></div><div><strong><?= count($lessons) ?></strong><span>μαθήματα σε 3 πυλώνες</span></div></div>
    <section class="ac-panel"><div class="ac-section-head"><h2>Ανά εργαζόμενο</h2><label class="ac-search">Αναζήτηση ονόματος / email<input type="search" data-ac-search placeholder="Βρες έναν εργαζόμενο…"></label></div>
      <div class="ac-table-wrap"><table class="ac-table"><caption class="ac-sr-only">Πρόοδος ενεργών λογαριασμών ανά εκπαιδευτικό πυλώνα</caption><thead><tr><th scope="col">Εργαζόμενος</th><th scope="col">Melas</th><th scope="col">Distillogic</th><th scope="col">CRM</th><th scope="col">Σύνολο</th></tr></thead><tbody>
      <?php foreach ($team as $member): ?><tr data-ac-searchable="<?= e($member['name'] . ' ' . $member['email']) ?>"><th scope="row"><strong><?= e($member['name']) ?></strong><small><?= e($member['email']) ?></small></th><?php foreach (array_keys($catalog) as $key): $s = academy_summary($member['progress'], $key); ?><td><?= $s['completed'] ?> / <?= $s['total'] ?></td><?php endforeach; ?><td><strong><?= $member['summary']['percent'] ?>%</strong><progress max="<?= $member['summary']['total'] ?>" value="<?= $member['summary']['completed'] ?>" aria-label="Συνολική πρόοδος <?= e($member['name']) ?>"></progress><details><summary>Ανάλυση μαθημάτων</summary><ul class="ac-team-detail"><?php foreach ($lessons as $id => $item): $p = $member['progress'][$id] ?? null; ?><li><strong><?= e($item['title']) ?></strong><span><?= !$p ? 'Χωρίς τεστ' : (!empty($p['completed_at']) ? 'Ολοκληρώθηκε' : 'Σε εξάσκηση') ?><?= $p ? ' · καλύτερο ' . (int)$p['best_score'] . '/' . (int)$p['question_count'] . ' · ' . (int)$p['attempts'] . ' προσπάθειες' : '' ?></span><?php if ($p): ?><small>Τελευταίο τεστ: <?= e(format_datetime($p['last_attempt_at'])) ?><?= !empty($p['completed_at']) ? ' · Ολοκλήρωση: ' . e(format_datetime($p['completed_at'])) : '' ?></small><?php endif; ?></li><?php endforeach; ?></ul></details></td></tr><?php endforeach; ?>
      </tbody></table></div><p class="ac-empty" data-ac-empty hidden>Δεν βρέθηκε αντίστοιχος λογαριασμός.</p>
    </section><p class="ac-fineprint">Η αναφορά δείχνει τεστ κατανόησης, όχι ώρες εργασίας ή αξιολόγηση εμπορικής απόδοσης. Δεν αλλάζει ρόλους, πρόσβαση ή εξουσιοδότηση. Ανενεργοί λογαριασμοί δεν εμφανίζονται· η αποθηκευμένη πρόοδός τους διατηρείται.</p>
  <?php elseif ($lesson): $pillar = $catalog[$lesson['pillar']]; $lessonProgress = $progress[$lessonId] ?? null; $ids = array_keys($lessons); $position = array_search($lessonId, $ids, true); $following = $ids[$position + 1] ?? null; ?>
    <div class="ac-breadcrumb"><a href="<?= e(crm_url('academy.php?pillar=' . $lesson['pillar'])) ?>">← <?= e($pillar['title']) ?></a><span>Μάθημα <?= $position + 1 ?> από <?= count($lessons) ?></span></div>
    <div class="ac-learning-layout">
      <aside class="ac-syllabus ac-panel"><p class="ac-kicker">Η ΔΙΑΔΡΟΜΗ ΣΟΥ</p><?php foreach ($catalog as $key => $group): ?><details <?= $key === $lesson['pillar'] ? 'open' : '' ?>><summary><?= e($group['title']) ?></summary><ol><?php foreach ($group['lessons'] as $id => $item): ?><li><a href="<?= e(crm_url('academy.php?lesson=' . $id)) ?>" <?= $id === $lessonId ? 'aria-current="step"' : '' ?>><span class="ac-step-state" aria-label="<?= !empty($progress[$id]['completed_at']) ? 'Ολοκληρώθηκε' : 'Δεν ολοκληρώθηκε' ?>"><?= !empty($progress[$id]['completed_at']) ? '✓' : '○' ?></span><?= e($item['title']) ?></a></li><?php endforeach; ?></ol></details><?php endforeach; ?></aside>
      <div class="ac-reading">
        <article class="ac-panel ac-lesson"><header><span class="ac-kicker"><?= e($pillar['tag']) ?></span><h1><?= e($lesson['title']) ?></h1><div class="ac-lesson-meta"><span>≈ <?= $lesson['minutes'] ?>′ μελέτη</span><span>3 ερωτήσεις</span><span><?= !empty($lessonProgress['completed_at']) ? '✓ Ολοκληρώθηκε' : 'Διάβασε · εξασκήσου · δοκίμασε' ?></span></div><p class="ac-goal"><?= e($lesson['goal']) ?></p></header>
        <?php foreach ($lesson['sections'] as [$heading, $paragraphs]): ?><section class="ac-chapter"><h2><?= e($heading) ?></h2><?php foreach ($paragraphs as $paragraph): ?><p><?= e($paragraph) ?></p><?php endforeach; ?></section><?php endforeach; ?>
        <section class="ac-scenario"><span class="ac-kicker">ΣΤΗΝ ΠΡΑΞΗ</span><h2>Εσύ τι θα έκανες;</h2><p><?= e($lesson['scenario'][0]) ?></p><details><summary>Δες μια ενδεικτική προσέγγιση</summary><p><?= e($lesson['scenario'][1]) ?></p></details></section>
        <?php if ($lessonId === 'me-commissions' || $lessonId === 'crm-entry'): ?>
        <section class="ac-lab" data-ac-lab><span class="ac-kicker">ΑΣΦΑΛΗΣ ΕΞΑΣΚΗΣΗ</span><h2>Δοκίμασε τους αριθμούς</h2><p>Μόνο εκπαιδευτικά δεδομένα. Δεν δημιουργείται lead, συναλλαγή ή προμήθεια.</p><div class="ac-lab-fields"><label>Πληρωμένα πάνελ<input type="number" min="0" max="1000000" step="1" value="2000" data-ac-quantity></label><label>Τιμή ανά πάνελ (€)<input type="number" min="0" max="1000000" step="0.01" value="20" data-ac-price></label></div><label class="ac-choice ac-inline"><input type="checkbox" data-ac-approved checked><span>Το qualified lead έχει εγκριθεί (€80)</span></label><output class="ac-lab-output" data-ac-output aria-live="polite"></output><p class="ac-fineprint">Οι αριθμοί είναι υποθετικοί. Στην πραγματική εργασία η ζητούμενη τιμή δεν είναι απόδειξη αγοράς ή πληρωμής.</p><noscript>Ο υπολογιστής χρειάζεται JavaScript. Τύποι: αξία = ποσότητα × τιμή, προμήθεια πάνελ = πληρωμένη ποσότητα × €0,60, συν €80 μόνο αν έχει εγκριθεί το lead.</noscript></section>
        <?php endif; ?>
        <p class="ac-source"><?= e($pillar['source']) ?> · Έκδοση μαθήματος <?= $lesson['version'] ?>.</p></article>
        <section class="ac-panel ac-quiz" id="quiz"><span class="ac-kicker">ΕΛΕΓΧΟΣ ΚΑΤΑΝΟΗΣΗΣ</span><h2>Ας το κάνουμε πράξη</h2><p>Το μάθημα ολοκληρώνεται με <strong>3/3 σωστές απαντήσεις</strong>. Μπορείς να ξαναδοκιμάσεις. Μια επανάληψη δεν αφαιρεί προηγούμενη επιτυχία.</p>
          <?php if ($result): $resultAnswers = json_decode($result['answers_json'], true); ?>
          <div class="ac-result <?= $result['passed'] ? 'is-pass' : 'is-retry' ?>" id="quiz-result" role="status" tabindex="-1"><strong><?= $result['passed'] ? 'Το ολοκλήρωσες!' : 'Μια ευκαιρία για επανάληψη' ?> · <?= (int)$result['score'] ?>/<?= (int)$result['question_count'] ?></strong><p><?= $result['passed'] ? 'Η πρόοδός σου αποθηκεύτηκε στον λογαριασμό σου.' : 'Διάβασε τις εξηγήσεις και δοκίμασε ξανά στο παρακάτω τεστ.' ?></p><ol><?php foreach ($lesson['quiz'] as $i => $question): ?><li><strong><?= e($question[0]) ?></strong><p>Η απάντησή σου: <?= e($question[1][$resultAnswers[$i]] ?? '—') ?> · <?= ($resultAnswers[$i] ?? null) === $question[2] ? 'Σωστή' : 'Χρειάζεται επανάληψη' ?></p><p><?= e($question[3]) ?></p></li><?php endforeach; ?></ol></div>
          <?php endif; ?>
          <form method="post" action="<?= e(crm_url('academy.php?lesson=' . $lessonId . '#quiz')) ?>" class="ac-quiz-form"><?= csrf_field() ?><input type="hidden" name="action" value="submit_quiz"><input type="hidden" name="version" value="<?= $lesson['version'] ?>"><input type="hidden" name="submission_key" value="<?= e(academy_form_key($lessonId)) ?>">
          <?php foreach ($lesson['quiz'] as $i => $question): ?><fieldset><legend><span><?= $i + 1 ?></span><?= e($question[0]) ?></legend><?php foreach ($question[1] as $option => $text): ?><label class="ac-choice"><input type="radio" name="answers[<?= $i ?>]" value="<?= $option ?>" required><span><?= e($text) ?></span></label><?php endforeach; ?></fieldset><?php endforeach; ?>
          <button class="ac-button" type="submit"><?= $result || $lessonProgress ? 'Υποβολή νέας προσπάθειας' : 'Υποβολή απαντήσεων' ?> <?= academy_icon('arrow') ?></button></form>
        </section>
        <nav class="ac-bottom-nav" aria-label="Επόμενο μάθημα"><a class="ac-button ac-secondary" href="<?= e(crm_url('academy.php')) ?>">Όλα τα μαθήματα</a><?php if ($following): ?><a class="ac-button" href="<?= e(crm_url('academy.php?lesson=' . $following)) ?>">Επόμενο μάθημα <?= academy_icon('arrow') ?></a><?php endif; ?></nav>
      </div>
    </div>
  <?php else: ?>
    <section class="ac-hero"><div class="ac-hero-copy"><span class="ac-kicker">Η ΓΝΩΣΗ ΓΙΝΕΤΑΙ ΕΥΚΑΙΡΙΑ</span><h1>Μάθε το αντικείμενο.<br>Κάνε το επόμενο βήμα.</h1><p>Τρεις πυλώνες. Μία κοινή βάση γνώσης.<br>Για ουσιαστικές συζητήσεις και σίγουρη χρήση των εργαλείων σου.</p><a class="ac-button" href="<?= e(crm_url('academy.php?lesson=' . ($summary['next'] ?? array_key_first($lessons)))) ?>"><?= $summary['completed'] === $summary['total'] ? 'Επανάληψη μαθημάτων' : ($summary['attempted'] ? 'Συνέχισε την εκπαίδευση' : 'Ξεκίνα την εκπαίδευση') ?> <?= academy_icon('arrow') ?></a></div><div class="ac-hero-progress"><span class="ac-orbit" aria-hidden="true"><?= academy_icon('book') ?></span><span class="ac-kicker">Η ΠΡΟΟΔΟΣ ΣΟΥ</span><strong><?= $summary['percent'] ?><small>%</small></strong><progress value="<?= $summary['completed'] ?>" max="<?= $summary['total'] ?>" aria-label="Η συνολική πρόοδός σου"></progress><p><?= $summary['completed'] ?> από <?= $summary['total'] ?> μαθήματα ολοκληρωμένα</p></div></section>
    <div class="ac-metrics"><div><strong>03</strong><span>εκπαιδευτικοί πυλώνες</span></div><div><strong><?= count($lessons) ?></strong><span>σύντομα μαθήματα</span></div><div><strong>45</strong><span>ερωτήσεις κατανόησης</span></div><div><strong>≈ <?= $summary['minutes'] ?>′</strong><span>ενδεικτική συνολική μελέτη</span></div></div>
    <?php if ($summary['completed'] === $summary['total']): ?><section class="ac-completion"><span><?= academy_icon('check') ?></span><div><h2>Ολοκλήρωσες την τρέχουσα διαδρομή!</h2><p>Όλα τα τεστ ολοκληρώθηκαν επιτυχώς. Συνέχισε με πρακτική καθοδήγηση από την ομάδα σου. Αυτό δεν αποτελεί εξωτερική πιστοποίηση ή αυτόματη εξουσιοδότηση.</p></div></section><?php endif; ?>
    <section aria-labelledby="ac-pillars-heading"><div class="ac-section-head"><div><span class="ac-kicker">ΤΟ ΠΡΟΓΡΑΜΜΑ ΣΟΥ</span><h2 id="ac-pillars-heading">Τρεις κόσμοι, μία διαδρομή.</h2></div><p>Μελέτη με τον δικό σου ρυθμό.</p></div><div class="ac-pillars"><?php foreach ($catalog as $key => $pillar): $s = academy_summary($progress, $key); ?><a class="ac-pillar ac-pillar-<?= e($key) ?>" href="<?= e(crm_url('academy.php?pillar=' . $key . '#lessons')) ?>"><span class="ac-pillar-icon"><?= academy_icon($pillar['icon']) ?></span><span class="ac-kicker"><?= e($pillar['tag']) ?></span><h3><?= e($pillar['title']) ?></h3><p><?= e($pillar['description']) ?></p><div class="ac-pillar-bottom"><span><?= $s['completed'] ?>/<?= $s['total'] ?> μαθήματα · ≈ <?= $s['minutes'] ?>′</span><?= academy_icon('arrow') ?></div><progress value="<?= $s['completed'] ?>" max="<?= $s['total'] ?>" aria-label="Πρόοδος <?= e($pillar['title']) ?>"></progress></a><?php endforeach; ?></div></section>
    <section class="ac-panel ac-curriculum" id="lessons"><div class="ac-section-head"><div><span class="ac-kicker">ΜΙΚΡΑ ΒΗΜΑΤΑ, ΣΤΑΘΕΡΗ ΕΞΕΛΙΞΗ</span><h2><?= $pillarId ? e($catalog[$pillarId]['title']) : 'Όλα τα μαθήματα' ?></h2></div><label class="ac-search">Αναζήτηση μαθήματος<input type="search" data-ac-search placeholder="π.χ. πάνελ, προμήθειες, CRM…"></label></div><nav class="ac-filters" aria-label="Φίλτρο πυλώνα"><a href="<?= e(crm_url('academy.php#lessons')) ?>" <?= !$pillarId ? 'aria-current="true"' : '' ?>>Όλα</a><?php foreach ($catalog as $key => $pillar): ?><a href="<?= e(crm_url('academy.php?pillar=' . $key . '#lessons')) ?>" <?= $pillarId === $key ? 'aria-current="true"' : '' ?>><?= e($pillar['title']) ?></a><?php endforeach; ?></nav>
    <ol class="ac-lessons"><?php $number = 0; foreach ($lessons as $id => $item): $number++; if ($pillarId && $item['pillar'] !== $pillarId) continue; $p = $progress[$id] ?? null; ?><li data-ac-searchable="<?= e($item['title'] . ' ' . $item['goal'] . ' ' . $item['pillar_title']) ?>"><a href="<?= e(crm_url('academy.php?lesson=' . $id)) ?>"><span class="ac-lesson-number <?= !empty($p['completed_at']) ? 'is-complete' : '' ?>"><?= !empty($p['completed_at']) ? academy_icon('check') : str_pad((string)$number, 2, '0', STR_PAD_LEFT) ?></span><span class="ac-lesson-label"><small><?= e($item['pillar_title']) ?> · ≈ <?= $item['minutes'] ?>′</small><strong><?= e($item['title']) ?></strong><span><?= e($item['goal']) ?></span></span><span class="ac-lesson-state"><?= !empty($p['completed_at']) ? 'Ολοκληρώθηκε' : ($p ? 'Συνέχισε' : 'Έναρξη') ?><?= academy_icon('arrow') ?></span></a></li><?php endforeach; ?></ol><p class="ac-empty" data-ac-empty hidden>Δεν βρέθηκε μάθημα με αυτόν τον όρο. Δοκίμασε άλλη λέξη ή επίλεξε «Όλα».</p></section>
    <section class="ac-how"><h2>Πώς λειτουργεί</h2><div><p><strong>01 / Μελέτη</strong>Διάβασε το μάθημα και σκέψου το πρακτικό σενάριο.</p><p><strong>02 / Κατανόηση</strong>Απάντησε σωστά και στις 3 ερωτήσεις. Έχεις δυνατότητα επανάληψης.</p><p><strong>03 / Εφαρμογή</strong>Η πρόοδος αποθηκεύεται στον λογαριασμό σου, και συνεχίζεις από οποιαδήποτε συσκευή.</p></div></section>
    <p class="ac-fineprint">Οι χρόνοι είναι ενδεικτικοί, όχι καταγραφή χρόνου εργασίας. Εσύ βλέπεις τη δική σου πρόοδο· ο Μελάς και ο εξουσιοδοτημένος διαχειριστής βλέπουν την πρόοδο της ομάδας. Δεν εισάγουμε στοιχεία πραγματικών πελατών στα εκπαιδευτικά παραδείγματα.</p>
  <?php endif; ?>
</div>
<script src="<?= e(crm_url('academy-v16.js?v=20260917')) ?>" defer></script>
<?php render_footer(); ?>
