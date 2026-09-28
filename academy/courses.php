<?php
declare(strict_types=1);
if (!defined('ACADEMY_ROOT')) { http_response_code(403); exit; }
require_once __DIR__ . '/content.php';
require_once __DIR__ . '/progression.php';
require_once __DIR__ . '/attempt-policy.php';

function can_view_academy_team(array $user): bool
{
    return academy_is_admin($user);
}

function ensure_academy_schema(): void
{
    static $ready = false;
    if ($ready) return;
    db()->exec("CREATE TABLE IF NOT EXISTS ".academy_table('progress')." (
        user_id CHAR(36) NOT NULL,
        lesson_id VARCHAR(64) NOT NULL,
        content_version SMALLINT UNSIGNED NOT NULL,
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        best_score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        last_score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        question_count SMALLINT UNSIGNED NOT NULL,
        completed_at DATETIME NULL,
        last_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, lesson_id, content_version),
        CONSTRAINT ".academy_constraint('progress_user_fk')." FOREIGN KEY (user_id) REFERENCES ".academy_table('users')."(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS ".academy_table('attempts')." (
        id CHAR(36) PRIMARY KEY,
        user_id CHAR(36) NOT NULL,
        lesson_id VARCHAR(64) NOT NULL,
        content_version SMALLINT UNSIGNED NOT NULL,
        submission_key CHAR(64) NOT NULL,
        score SMALLINT UNSIGNED NOT NULL,
        question_count SMALLINT UNSIGNED NOT NULL,
        passed TINYINT(1) NOT NULL,
        answers_json TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY academy_submission_unique (user_id, submission_key),
        KEY academy_attempt_user_idx (user_id, lesson_id, created_at),
        CONSTRAINT ".academy_constraint('attempt_user_fk')." FOREIGN KEY (user_id) REFERENCES ".academy_table('users')."(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec('CREATE TABLE IF NOT EXISTS '.academy_table('quiz_snapshots').' (attempt_id CHAR(36) PRIMARY KEY,questions_json LONGTEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    academy_attempt_policy_schema();
    $ready = true;
}

function academy_lesson(string $id): array
{
    $lesson = academy_lessons()[$id] ?? null;
    if (!$lesson) throw new InvalidArgumentException('Το μάθημα δεν βρέθηκε.');
    return $lesson;
}

function academy_grade(array $lesson, array $answers): array
{
    if (($lesson['type'] ?? '') === 'lab') throw new InvalidArgumentException('Η πρακτική αξιολόγηση γίνεται μόνο μέσα στο CRM Lab.');
    $count = count($lesson['quiz']);
    if (count($answers) !== $count) throw new InvalidArgumentException('Απάντησε σε όλες τις ερωτήσεις πριν υποβάλεις το τεστ.');
    $normalized = [];
    $score = 0;
    foreach ($lesson['quiz'] as $index => $question) {
        $answer = $answers[$index] ?? null;
        if ((!is_int($answer) && !is_string($answer)) || !preg_match('/^[0-9]+$/D', (string)$answer)
            || !array_key_exists((int)$answer, $question[1])) {
            throw new InvalidArgumentException('Μία απάντηση δεν είναι έγκυρη. Επίλεξε ξανά από τις διαθέσιμες απαντήσεις.');
        }
        $normalized[] = (int)$answer;
        if ((int)$answer === $question[2]) $score++;
    }
    return ['score' => $score, 'total' => $count, 'passed' => $count>0 && $score*100 >= $count*80, 'answers' => $normalized];
}

function academy_record_attempt(array $user, string $lessonId, array $answers, string $submissionKey): string
{
    if (empty($user['active']) || empty($user['id'])) throw new RuntimeException('Απαιτείται ενεργός λογαριασμός.');
    if (!preg_match('/^[a-f0-9]{64}$/D', $submissionKey)) throw new InvalidArgumentException('Άκυρο αναγνωριστικό τεστ. Άνοιξε ξανά το μάθημα.');
    $lesson = academy_lesson($lessonId);
    $grade = academy_grade($lesson, $answers);
    ensure_academy_schema();
    academy_assert_unlocked($user,$lessonId);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Serialise attempts for this learner, including two tabs submitting together.
        $lock = $pdo->prepare('SELECT id FROM '.academy_table('users').' WHERE id = ? AND active = 1 FOR UPDATE');
        $lock->execute([$user['id']]);
        if (!$lock->fetchColumn()) throw new RuntimeException('Ο λογαριασμός δεν είναι ενεργός.');
        $existing = $pdo->prepare('SELECT * FROM '.academy_table('attempts').' WHERE user_id = ? AND submission_key = ?');
        $existing->execute([$user['id'], $submissionKey]);
        if ($row = $existing->fetch()) {
            if ($row['lesson_id'] !== $lessonId || (int)$row['content_version'] !== $lesson['version']
                || json_decode($row['answers_json'], true) !== $grade['answers']) {
                throw new InvalidArgumentException('Αυτό το τεστ υποβλήθηκε ήδη. Άνοιξε νέο τεστ για άλλη προσπάθεια.');
            }
            $pdo->commit();
            return $row['id'];
        }
        $attemptState = academy_attempt_assert($user, $lessonId);
        $id = uuid_v4();
        $insert = $pdo->prepare('INSERT INTO '.academy_table('attempts').'
            (id,user_id,lesson_id,content_version,submission_key,score,question_count,passed,answers_json)
            VALUES (?,?,?,?,?,?,?,?,?)');
        $insert->execute([$id, $user['id'], $lessonId, $lesson['version'], $submissionKey,
            $grade['score'], $grade['total'], $grade['passed'] ? 1 : 0, json_encode($grade['answers'], JSON_THROW_ON_ERROR)]);
        $pdo->prepare('INSERT INTO '.academy_table('quiz_snapshots').' (attempt_id,questions_json) VALUES(?,?)')->execute([$id,json_encode($lesson['quiz'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        $progress = $pdo->prepare('INSERT INTO '.academy_table('progress').'
            (user_id,lesson_id,content_version,attempts,best_score,last_score,question_count,completed_at,last_attempt_at)
            VALUES (?,?,?,1,?,?,?,IF(?=1,NOW(),NULL),NOW())
            ON DUPLICATE KEY UPDATE attempts=attempts+1,
            best_score=GREATEST(best_score,VALUES(best_score)), last_score=VALUES(last_score),
            question_count=VALUES(question_count), completed_at=COALESCE(completed_at,VALUES(completed_at)), last_attempt_at=NOW()');
        $progress->execute([$user['id'], $lessonId, $lesson['version'], $grade['score'], $grade['score'], $grade['total'], $grade['passed'] ? 1 : 0]);
        academy_attempt_consume($user, $lessonId, $id, $attemptState);
        $pdo->commit();
        return $id;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function academy_my_progress(array $user): array
{
    $q = db()->prepare('SELECT * FROM '.academy_table('progress').' WHERE user_id = ?');
    $q->execute([$user['id']]);
    return academy_current_progress($q->fetchAll());
}

function academy_current_progress(array $rows): array
{
    return academy_official_progress($rows);
}

function academy_summary(array $progress, ?string $pillar = null): array
{
    $total = $completed = $attempted = $minutes = 0;
    $next = null;
    foreach (academy_lessons() as $id => $lesson) {
        if ($pillar !== null && $lesson['pillar'] !== $pillar) continue;
        $total++;
        $minutes += $lesson['minutes'];
        if (isset($progress[$id])) $attempted++;
        if (!empty($progress[$id]['completed_at'])) $completed++;
        elseif ($next === null) $next = $id;
    }
    return ['total' => $total, 'completed' => $completed, 'attempted' => $attempted,
        'percent' => $total ? (int)round($completed * 100 / $total) : 0, 'minutes' => $minutes, 'next' => $next];
}

function academy_my_result(array $user, string $id, string $lessonId): ?array
{
    $lesson = academy_lesson($lessonId);
    $q = db()->prepare('SELECT * FROM '.academy_table('attempts').' WHERE id=? AND user_id=? AND lesson_id=? AND content_version=?');
    $q->execute([$id, $user['id'], $lessonId, $lesson['version']]);
    $result=$q->fetch();
    if (!$result) return null;
    $q=db()->prepare('SELECT questions_json FROM '.academy_table('quiz_snapshots').' WHERE attempt_id=?');$q->execute([$id]);$snapshot=$q->fetchColumn();
    $result['questions']=$snapshot ? json_decode($snapshot,true,512,JSON_THROW_ON_ERROR) : ($lesson['legacy_quiz']??$lesson['quiz']);
    return $result;
}

function academy_team(array $viewer): array
{
    // Permission check lives here as well as in the route; no user-controlled target ID.
    if (!can_view_academy_team($viewer)) throw new RuntimeException('Δεν έχετε πρόσβαση στην πρόοδο άλλων χρηστών.');
    $users = db()->query('SELECT id,name,email,role FROM '.academy_table('users').' WHERE active=1 ORDER BY name,id')->fetchAll();
    $rows = db()->query('SELECT p.* FROM '.academy_table('progress').' p JOIN '.academy_table('users').' u ON u.id=p.user_id WHERE u.active=1')->fetchAll();
    $byUser = [];
    foreach ($rows as $row) $byUser[$row['user_id']][] = $row;
    foreach ($users as &$user) {
        $user['progress'] = academy_current_progress($byUser[$user['id']] ?? []);
        $user['summary'] = academy_summary($user['progress']);
    }
    unset($user);
    return $users;
}

function academy_form_key(string $lessonId): string
{
    academy_session();
    $lesson = academy_lesson($lessonId);
    $key = $lessonId . ':' . $lesson['version'] . ':' . hash('sha256',json_encode($lesson['quiz'],JSON_THROW_ON_ERROR));
    if (!isset($_SESSION['academy_forms'][$key])) $_SESSION['academy_forms'][$key] = bin2hex(random_bytes(32));
    return $_SESSION['academy_forms'][$key];
}

function academy_validate_form(string $lessonId, mixed $version, mixed $key): void
{
    $lesson = academy_lesson($lessonId);
    if (!is_scalar($version) || (string)$version !== (string)$lesson['version']) {
        throw new InvalidArgumentException('Το μάθημα ενημερώθηκε. Άνοιξέ το ξανά πριν κάνεις το τεστ.');
    }
    if (!is_string($key) || !hash_equals(academy_form_key($lessonId), $key)) {
        throw new InvalidArgumentException('Το τεστ έχει ήδη υποβληθεί ή η φόρμα έχει λήξει. Άνοιξε ξανά το μάθημα.');
    }
}

function academy_icon(string $name): string
{
    $paths = [
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.4 1.4m11.2 11.2L19 19M5 19l1.4-1.4M17.6 6.4 19 5"/>',
        'code' => '<path d="m8 6-6 6 6 6m8-12 6 6-6 6M14 3l-4 18"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><path d="M17.5 14v7M14 17.5h7"/>',
        'arrow' => '<path d="M4 12h16m-6-6 6 6-6 6"/>',
        'book' => '<path d="M12 5c-3-2-6-2-10-1v15c4-1 7-1 10 1 3-2 6-2 10-1V4c-4-1-7-1-10 1Zm0 0v15"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
    ];
    return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['book']) . '</svg>';
}
