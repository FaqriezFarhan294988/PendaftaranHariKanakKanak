<?php
require __DIR__ . '/config.php';

$secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'secure' => $secure,
  'httponly' => true,
  'samesite' => 'Strict'
]);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function done(int $status, array $data): void {
  http_response_code($status);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

function request_body(): array {
  $raw = file_get_contents('php://input');
  if ($raw === '') return [];
  $data = json_decode($raw, true);
  if (!is_array($data)) done(400, ['ok' => false, 'message' => 'Badan permintaan bukan JSON yang sah.']);
  return $data;
}

function require_method(string $method): void {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) done(405, ['ok' => false, 'message' => 'Kaedah permintaan tidak dibenarkan.']);
}

function admin_required(): void {
  if (empty($_SESSION['admin'])) done(401, ['ok' => false, 'message' => 'Sila log masuk sebagai pentadbir.']);
}

function csrf_required(): void {
  $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
  if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $provided)) {
    done(419, ['ok' => false, 'message' => 'Sesi telah tamat. Muat semula halaman dan cuba lagi.']);
  }
}

function admin_post(): array {
  require_method('POST');
  admin_required();
  csrf_required();
  return request_body();
}

function uuid(): string {
  $bytes = random_bytes(16);
  $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
  $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
  $hex = bin2hex($bytes);
  return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function log_activity(PDO $pdo, string $action, string $candidateId, string $teamId, string $description, string $actor): void {
  $stmt = $pdo->prepare('INSERT INTO activity_logs (action, candidate_id, team_id, description, actor) VALUES (?, ?, ?, ?, ?)');
  $stmt->execute([$action, $candidateId ?: null, $teamId ?: null, $description, $actor]);
}

function category_for_age(int $age): string {
  if ($age >= 4 && $age <= 6) return 'CAT-1';
  if ($age >= 7 && $age <= 12) return 'CAT-2';
  if ($age >= 13 && $age <= 15) return 'CAT-3';
  throw new InvalidArgumentException('Umur calon mestilah antara 4 hingga 15 tahun.');
}

function category_label(string $category): string {
  return ['CAT-1' => 'UMUR 4–6 TAHUN', 'CAT-2' => 'UMUR 7–12 TAHUN', 'CAT-3' => 'UMUR 13–15 TAHUN'][$category] ?? 'TIDAK DIKETAHUI';
}

function clean_text($value, int $maxLength): string {
  $value = preg_replace('/\s+/u', ' ', trim((string)$value));
  return mb_substr($value ?? '', 0, $maxLength, 'UTF-8');
}

function validate_candidate($candidate): array {
  if (!is_array($candidate)) throw new InvalidArgumentException('Maklumat calon tidak sah.');
  $name = clean_text($candidate['name'] ?? '', 120);
  $ic = preg_replace('/\D/', '', (string)($candidate['ic'] ?? ''));
  $age = filter_var($candidate['age'] ?? null, FILTER_VALIDATE_INT);
  if ($name === '') throw new InvalidArgumentException('Nama penuh calon diperlukan.');
  if (!preg_match('/^[0-9]{12}$/', $ic)) throw new InvalidArgumentException('Nombor kad pengenalan mestilah 12 digit.');
  if ($age === false) throw new InvalidArgumentException('Umur calon tidak sah.');
  return ['name' => $name, 'ic' => $ic, 'age' => $age, 'category' => category_for_age($age)];
}

function validate_representative($representative): array {
  if (!is_array($representative)) throw new InvalidArgumentException('Maklumat wakil tidak sah.');
  $name = clean_text($representative['name'] ?? '', 120);
  $phone = clean_text($representative['phone'] ?? '', 30);
  $position = clean_text($representative['position'] ?? '', 120);
  $age = filter_var($representative['age'] ?? null, FILTER_VALIDATE_INT);
  $attendance = (string)($representative['attendance'] ?? '');
  if ($name === '' || $position === '') throw new InvalidArgumentException('Nama dan jawatan wakil diperlukan.');
  if (!preg_match('/^[+0-9() .-]{7,30}$/', $phone)) throw new InvalidArgumentException('Masukkan nombor telefon wakil yang sah.');
  if ($age === false || $age < 18 || $age > 100) throw new InvalidArgumentException('Umur wakil mestilah antara 18 hingga 100 tahun.');
  if (!in_array($attendance, ['HADIR', 'TIDAK HADIR'], true)) throw new InvalidArgumentException('Pilih status kehadiran wakil.');
  return ['name' => $name, 'phone' => $phone, 'position' => $position, 'age' => $age, 'attendance' => $attendance];
}

function dashboard(PDO $pdo): array {
  $teams = $pdo->query(
    "SELECT t.id, t.name, q.overall_quota, q.quota_4_6, q.quota_7_12, q.quota_13_15,
      COUNT(c.id) AS registered,
      SUM(c.category = 'CAT-1') AS count_4_6,
      SUM(c.category = 'CAT-2') AS count_7_12,
      SUM(c.category = 'CAT-3') AS count_13_15
     FROM teams t JOIN quotas q ON q.team_id = t.id
     LEFT JOIN candidates c ON c.team_id = t.id
     WHERE t.status = 'ACTIVE' GROUP BY t.id ORDER BY t.name"
  )->fetchAll();
  $categoryDefinitions = [
    ['id' => 'CAT-1', 'label' => 'UMUR 4–6 TAHUN', 'count' => 'count_4_6', 'quota' => 'quota_4_6'],
    ['id' => 'CAT-2', 'label' => 'UMUR 7–12 TAHUN', 'count' => 'count_7_12', 'quota' => 'quota_7_12'],
    ['id' => 'CAT-3', 'label' => 'UMUR 13–15 TAHUN', 'count' => 'count_13_15', 'quota' => 'quota_13_15']
  ];
  $summary = [];
  foreach ($categoryDefinitions as $category) $summary[$category['id']] = ['id' => $category['id'], 'label' => $category['label'], 'registered' => 0, 'quota' => 0];
  $result = [];
  foreach ($teams as $team) {
    $registered = (int)$team['registered'];
    $overall = (int)$team['overall_quota'];
    $categories = [];
    foreach ($categoryDefinitions as $definition) {
      $count = (int)$team[$definition['count']];
      $quota = (int)$team[$definition['quota']];
      $categories[] = ['id' => $definition['id'], 'label' => $definition['label'], 'registered' => $count, 'quota' => $quota, 'remaining' => max(0, $quota - $count), 'percent' => $quota > 0 ? (int)round($count * 100 / $quota) : 0];
      $summary[$definition['id']]['registered'] += $count;
      $summary[$definition['id']]['quota'] += $quota;
    }
    $categoriesComplete = array_sum(array_column($categories, 'quota')) > 0;
    foreach ($categories as $category) if ($category['registered'] < $category['quota']) $categoriesComplete = false;
    $status = $overall > 0 && $registered >= $overall ? 'KUOTA PENUH' : ($categoriesComplete ? 'SELESAI' : ($registered === 0 ? 'BELUM BERMULA' : 'SEDANG BERJALAN'));
    $result[] = ['id' => $team['id'], 'name' => $team['name'], 'overall' => $overall, 'registered' => $registered, 'remaining' => max(0, $overall - $registered), 'percent' => $overall > 0 ? (int)round($registered * 100 / $overall) : 0, 'status' => $status, 'categories' => $categories];
  }
  $categories = [];
  foreach ($summary as $item) {
    $item['remaining'] = max(0, $item['quota'] - $item['registered']);
    $item['percent'] = $item['quota'] > 0 ? (int)round($item['registered'] * 100 / $item['quota']) : 0;
    $categories[] = $item;
  }
  $registeredTotal = array_sum(array_column($result, 'registered'));
  $quotaTotal = array_sum(array_column($result, 'overall'));
  return [
    'teams' => $result,
    'categories' => $categories,
    'stats' => ['totalTeams' => count($result), 'registered' => $registeredTotal, 'totalQuota' => $quotaTotal, 'remaining' => max(0, $quotaTotal - $registeredTotal), 'percent' => $quotaTotal > 0 ? (int)round($registeredTotal * 100 / $quotaTotal) : 0, 'completedTeams' => count(array_filter($result, static fn($team) => $team['status'] === 'SELESAI'))]
  ];
}

function team_progress(PDO $pdo, string $teamId): array {
  $progress = dashboard($pdo);
  foreach ($progress['teams'] as $team) if ($team['id'] === $teamId) return $team;
  done(404, ['ok' => false, 'message' => 'Pasukan tidak ditemui.']);
}

function candidate_list(PDO $pdo): array {
  return $pdo->query(
    "SELECT c.id AS candidateId, c.registration_id AS registrationId, c.name, c.ic_number AS ic, c.age,
      CASE c.category WHEN 'CAT-1' THEN 'UMUR 4–6 TAHUN' WHEN 'CAT-2' THEN 'UMUR 7–12 TAHUN' WHEN 'CAT-3' THEN 'UMUR 13–15 TAHUN' ELSE 'TIDAK DIKETAHUI' END AS category,
      c.team_id AS teamId, t.name AS teamName, r.representative_name AS representativeName, c.created_at AS registrationDate
     FROM candidates c JOIN teams t ON t.id = c.team_id JOIN registrations r ON r.id = c.registration_id
     ORDER BY c.created_at DESC"
  )->fetchAll();
}

function fetch_teams(PDO $pdo): array {
  return $pdo->query("SELECT id, name FROM teams WHERE status = 'ACTIVE' ORDER BY name")->fetchAll();
}

$action = (string)($_GET['action'] ?? '');
$body = request_body();

try {
  $pdo = db();
  if ($action === 'admin_csrf') {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    done(200, ['ok' => true, 'csrf' => $_SESSION['csrf'], 'authenticated' => !empty($_SESSION['admin'])]);
  }

  if ($action === 'get_teams') {
    require_method('GET');
    done(200, ['ok' => true, 'teams' => fetch_teams($pdo)]);
  }

  if ($action === 'get_team_progress') {
    require_method('GET');
    $teamId = trim((string)($_GET['teamId'] ?? ''));
    if ($teamId === '') done(422, ['ok' => false, 'message' => 'Pilih pasukan untuk melihat kemajuan.']);
    done(200, ['ok' => true, 'team' => team_progress($pdo, $teamId)]);
  }

  if ($action === 'submit_registration') {
    require_method('POST');
    $teamId = trim((string)($body['teamId'] ?? ''));
    $candidates = $body['candidates'] ?? null;
    if ($teamId === '' || !is_array($candidates) || count($candidates) < 1 || count($candidates) > 50) {
      done(422, ['ok' => false, 'message' => 'Pilih pasukan dan hantar antara 1 hingga 50 calon bersama maklumat wakil.']);
    }
    $representative = validate_representative($body['representative'] ?? null);
    $pdo->beginTransaction();
    $teamStmt = $pdo->prepare("SELECT id, name FROM teams WHERE id = ? AND status = 'ACTIVE' FOR UPDATE");
    $teamStmt->execute([$teamId]);
    $team = $teamStmt->fetch();
    if (!$team) throw new InvalidArgumentException('Pilih pasukan yang sah.');
    $quotaStmt = $pdo->prepare('SELECT overall_quota AS overall, quota_4_6 AS quota4to6, quota_7_12 AS quota7to12, quota_13_15 AS quota13to15 FROM quotas WHERE team_id = ?');
    $quotaStmt->execute([$teamId]);
    $quota = $quotaStmt->fetch();
    if (!$quota) throw new RuntimeException('Kuota pasukan belum disediakan.');
    $countStmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(category = 'CAT-1') AS c46, SUM(category = 'CAT-2') AS c712, SUM(category = 'CAT-3') AS c1315 FROM candidates WHERE team_id = ?");
    $countStmt->execute([$teamId]);
    $counts = $countStmt->fetch();
    $total = (int)$counts['total'];
    $categoryCounts = ['CAT-1' => (int)$counts['c46'], 'CAT-2' => (int)$counts['c712'], 'CAT-3' => (int)$counts['c1315']];
    $quotaKeys = ['CAT-1' => 'quota4to6', 'CAT-2' => 'quota7to12', 'CAT-3' => 'quota13to15'];
    $accepted = [];
    $rejected = [];
    $newCandidates = [];
    $usedIc = [];
    foreach ($candidates as $index => $candidate) {
      try {
        $clean = validate_candidate($candidate);
        if (isset($usedIc[$clean['ic']])) throw new InvalidArgumentException('Nombor kad pengenalan telah diulang dalam borang ini.');
        $duplicate = $pdo->prepare('SELECT 1 FROM candidates WHERE ic_number = ?');
        $duplicate->execute([$clean['ic']]);
        if ($duplicate->fetchColumn()) throw new InvalidArgumentException('Nombor kad pengenalan telah didaftarkan.');
        if ($total >= (int)$quota['overall']) throw new InvalidArgumentException('Kuota keseluruhan pasukan telah penuh.');
        if ($categoryCounts[$clean['category']] >= (int)$quota[$quotaKeys[$clean['category']]]) throw new InvalidArgumentException('Kuota kategori ' . category_label($clean['category']) . ' telah penuh.');
        $usedIc[$clean['ic']] = true;
        $total++;
        $categoryCounts[$clean['category']]++;
        $newCandidates[] = ['id' => uuid(), 'data' => $clean];
        $accepted[] = ['name' => $clean['name'], 'category' => category_label($clean['category'])];
      } catch (InvalidArgumentException $error) {
        $rejected[] = ['index' => $index + 1, 'name' => clean_text(is_array($candidate) ? ($candidate['name'] ?? '') : '', 120), 'reason' => $error->getMessage()];
      }
    }
    $registrationId = uuid();
    if ($newCandidates) {
      $register = $pdo->prepare('INSERT INTO registrations (id, team_id, representative_name, representative_phone, representative_position, representative_age, representative_attendance) VALUES (?, ?, ?, ?, ?, ?, ?)');
      $register->execute([$registrationId, $teamId, $representative['name'], $representative['phone'], $representative['position'], $representative['age'], $representative['attendance']]);
      $insert = $pdo->prepare('INSERT INTO candidates (id, registration_id, team_id, name, ic_number, age, category) VALUES (?, ?, ?, ?, ?, ?, ?)');
      foreach ($newCandidates as $candidate) $insert->execute([$candidate['id'], $registrationId, $teamId, $candidate['data']['name'], $candidate['data']['ic'], $candidate['data']['age'], $candidate['data']['category']]);
      log_activity($pdo, 'CANDIDATE_CREATE', $newCandidates[0]['id'], $teamId, 'Pendaftaran ' . $registrationId . ' menerima ' . count($newCandidates) . ' calon.', 'USER');
    }
    $pdo->commit();
    done(200, ['ok' => true, 'registrationId' => $registrationId, 'team' => ['id' => $team['id'], 'name' => $team['name']], 'accepted' => $accepted, 'rejected' => $rejected, 'progress' => team_progress($pdo, $teamId)]);
  }

  if ($action === 'admin_login') {
    require_method('POST');
    csrf_required();
    if (ADMIN_PASSWORD_HASH === '') done(503, ['ok' => false, 'message' => 'Kata laluan pentadbir belum ditetapkan. Ikuti arahan dalam SETUP.md.']);
    if (!password_verify((string)($body['password'] ?? ''), ADMIN_PASSWORD_HASH)) done(401, ['ok' => false, 'message' => 'Kata laluan tidak betul.']);
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    log_activity($pdo, 'ADMIN_LOGIN', '', '', 'Log masuk pentadbir', 'ADMIN');
    done(200, ['ok' => true, 'csrf' => $_SESSION['csrf']]);
  }

  if ($action === 'admin_logout') {
    require_method('POST');
    admin_required();
    csrf_required();
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    done(200, ['ok' => true, 'csrf' => $_SESSION['csrf']]);
  }

  $adminGetActions = ['admin_dashboard', 'get_quotas', 'get_candidates', 'get_logs'];
  if (in_array($action, $adminGetActions, true)) {
    require_method('GET');
    admin_required();
    if ($action === 'admin_dashboard' || $action === 'get_quotas') {
      $summary = dashboard($pdo);
      done(200, ['ok' => true, 'dashboard' => $summary, 'teams' => $summary['teams']]);
    }
    if ($action === 'get_candidates') done(200, ['ok' => true, 'candidates' => candidate_list($pdo)]);
    $logs = $pdo->query('SELECT id, action, candidate_id AS candidateId, team_id AS teamId, description, created_at AS timestamp, actor FROM activity_logs ORDER BY id DESC LIMIT 500')->fetchAll();
    done(200, ['ok' => true, 'logs' => $logs]);
  }

  if (in_array($action, ['update_quota', 'edit_candidate', 'delete_candidate'], true)) {
    $body = admin_post();
    if ($action === 'update_quota') {
      $teamId = trim((string)($body['teamId'] ?? ''));
      $keys = ['overall', 'quota4to6', 'quota7to12', 'quota13to15'];
      $values = [];
      foreach ($keys as $key) {
        $value = filter_var($body[$key] ?? null, FILTER_VALIDATE_INT);
        if ($value === false || $value < 0) done(422, ['ok' => false, 'message' => 'Nilai kuota mestilah nombor bulat sifar atau lebih.']);
        $values[] = $value;
      }
      if (array_sum(array_slice($values, 1)) > $values[0]) done(422, ['ok' => false, 'message' => 'Jumlah kuota kategori tidak boleh melebihi kuota keseluruhan.']);
      $pdo->beginTransaction();
      $lock = $pdo->prepare('SELECT id FROM teams WHERE id = ? FOR UPDATE');
      $lock->execute([$teamId]);
      if (!$lock->fetch()) throw new InvalidArgumentException('Pasukan tidak ditemui.');
      $countsStmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(category = 'CAT-1') AS c46, SUM(category = 'CAT-2') AS c712, SUM(category = 'CAT-3') AS c1315 FROM candidates WHERE team_id = ?");
      $countsStmt->execute([$teamId]);
      $count = $countsStmt->fetch();
      if ($values[0] < (int)$count['total']) throw new InvalidArgumentException('Kuota keseluruhan tidak boleh kurang daripada jumlah calon semasa (' . (int)$count['total'] . ').');
      foreach (array_slice(['c46', 'c712', 'c1315'], 0, 3) as $index => $field) if ($values[$index + 1] < (int)$count[$field]) throw new InvalidArgumentException('Kuota kategori tidak boleh kurang daripada jumlah calon semasa.');
      $update = $pdo->prepare('UPDATE quotas SET overall_quota = ?, quota_4_6 = ?, quota_7_12 = ?, quota_13_15 = ? WHERE team_id = ?');
      $update->execute([$values[0], $values[1], $values[2], $values[3], $teamId]);
      log_activity($pdo, 'QUOTA_UPDATE', '', $teamId, 'Kuota dikemas kini: ' . implode('/', $values), 'ADMIN');
      $pdo->commit();
      done(200, ['ok' => true, 'progress' => team_progress($pdo, $teamId)]);
    }

    $candidateId = trim((string)($body['id'] ?? ''));
    if ($candidateId === '') done(422, ['ok' => false, 'message' => 'ID calon diperlukan.']);
    if ($action === 'delete_candidate') {
      $stmt = $pdo->prepare('SELECT id, name, registration_id AS registrationId, team_id AS teamId, ic_number AS ic FROM candidates WHERE id = ?');
      $stmt->execute([$candidateId]);
      $candidate = $stmt->fetch();
      if (!$candidate) done(404, ['ok' => false, 'message' => 'Rekod calon tidak ditemui.']);
      $pdo->beginTransaction();
      log_activity($pdo, 'CANDIDATE_DELETE', $candidateId, $candidate['teamId'], 'Rekod calon ' . $candidate['name'] . ' (' . $candidate['ic'] . ') dipadam.', 'ADMIN');
      $delete = $pdo->prepare('DELETE FROM candidates WHERE id = ?');
      $delete->execute([$candidateId]);
      $remaining = $pdo->prepare('SELECT COUNT(*) FROM candidates WHERE registration_id = ?');
      $remaining->execute([$candidate['registrationId']]);
      if ((int)$remaining->fetchColumn() === 0) {
        $deleteRegistration = $pdo->prepare('DELETE FROM registrations WHERE id = ?');
        $deleteRegistration->execute([$candidate['registrationId']]);
      }
      $pdo->commit();
      done(200, ['ok' => true, 'progress' => team_progress($pdo, $candidate['teamId'])]);
    }

    $clean = validate_candidate($body['candidate'] ?? null);
    $teamId = trim((string)($body['teamId'] ?? ''));
    $pdo->beginTransaction();
    $pdo->query('SELECT id FROM teams ORDER BY id FOR UPDATE')->fetchAll();
    $stmt = $pdo->prepare('SELECT id, registration_id, team_id AS teamId FROM candidates WHERE id = ? FOR UPDATE');
    $stmt->execute([$candidateId]);
    $existing = $stmt->fetch();
    if (!$existing) throw new InvalidArgumentException('Rekod calon tidak ditemui.');
    $teamStmt = $pdo->prepare("SELECT id, name FROM teams WHERE id = ? AND status = 'ACTIVE'");
    $teamStmt->execute([$teamId]);
    $team = $teamStmt->fetch();
    if (!$team) throw new InvalidArgumentException('Pilih pasukan yang sah.');
    $duplicate = $pdo->prepare('SELECT 1 FROM candidates WHERE ic_number = ? AND id <> ?');
    $duplicate->execute([$clean['ic'], $candidateId]);
    if ($duplicate->fetchColumn()) throw new InvalidArgumentException('Nombor kad pengenalan telah didaftarkan.');
    $quotaStmt = $pdo->prepare('SELECT overall_quota AS overall, quota_4_6 AS quota4to6, quota_7_12 AS quota7to12, quota_13_15 AS quota13to15 FROM quotas WHERE team_id = ?');
    $quotaStmt->execute([$teamId]);
    $quota = $quotaStmt->fetch();
    $countStmt = $pdo->prepare('SELECT COUNT(*) AS total, SUM(category = ?) AS categoryCount FROM candidates WHERE team_id = ? AND id <> ?');
    $countStmt->execute([$clean['category'], $teamId, $candidateId]);
    $counts = $countStmt->fetch();
    $quotaKey = ['CAT-1' => 'quota4to6', 'CAT-2' => 'quota7to12', 'CAT-3' => 'quota13to15'][$clean['category']];
    if ((int)$counts['total'] + 1 > (int)$quota['overall'] || (int)$counts['categoryCount'] + 1 > (int)$quota[$quotaKey]) throw new InvalidArgumentException('Tiada ruang kuota untuk pasukan atau kategori pilihan.');
    $update = $pdo->prepare('UPDATE candidates SET name = ?, ic_number = ?, age = ?, category = ?, team_id = ? WHERE id = ?');
    $update->execute([$clean['name'], $clean['ic'], $clean['age'], $clean['category'], $teamId, $candidateId]);
    log_activity($pdo, 'CANDIDATE_EDIT', $candidateId, $teamId, 'Rekod calon ' . $clean['name'] . ' dikemas kini.', 'ADMIN');
    $pdo->commit();
    done(200, ['ok' => true, 'progress' => team_progress($pdo, $teamId)]);
  }

  done(404, ['ok' => false, 'message' => 'Tindakan API tidak dikenali.']);
} catch (InvalidArgumentException $error) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  done(422, ['ok' => false, 'message' => $error->getMessage()]);
} catch (Throwable $error) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  error_log('Ralat API pendaftaran calon: ' . $error->getMessage());
  if ($error instanceof PDOException && $error->getCode() === '23000') done(422, ['ok' => false, 'message' => 'Nombor kad pengenalan telah didaftarkan atau data tidak memenuhi peraturan.']);
  done(500, ['ok' => false, 'message' => 'Permintaan tidak dapat diselesaikan. Semak sambungan pangkalan data dan log pelayan.']);
}
