<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Ho_Chi_Minh');

require_once __DIR__ . '/../migrations/001_class_attendance.php';

function envValue(string $name, ?string $default = null): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        if ($default !== null) {
            return $default;
        }
        throw new RuntimeException("Missing required environment variable: {$name}");
    }
    return $value;
}

function database(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        envValue('DB_HOST'),
        envValue('DB_PORT', '3306'),
        envValue('DB_NAME')
    );
    $pdo = new PDO($dsn, envValue('DB_USER'), envValue('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    migrateClassAttendance($pdo);

    return $pdo;
}

function seedAdministrator(PDO $pdo): int
{
    $email = strtolower(trim(envValue('APP_ADMIN_EMAIL')));
    $name = trim(envValue('APP_ADMIN_NAME', 'Attendance Admin'));
    $password = envValue('APP_ADMIN_PASSWORD');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
        throw new InvalidArgumentException('Cấu hình quản trị không hợp lệ: email phải đúng định dạng và mật khẩu phải có ít nhất 12 ký tự.');
    }

    $admin = $pdo->query('SELECT id, name, email, password_hash FROM employees ORDER BY id ASC LIMIT 1')->fetch();
    if ($admin === false) {
        $insert = $pdo->prepare('INSERT INTO employees (name, email, password_hash) VALUES (?, ?, ?)');
        $insert->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        return (int) $pdo->lastInsertId();
    }

    $emailQuery = $pdo->prepare('SELECT id FROM employees WHERE email = ?');
    $emailQuery->execute([$email]);
    $emailOwner = $emailQuery->fetchColumn();
    if ($emailOwner !== false && (int) $emailOwner !== (int) $admin['id']) {
        throw new RuntimeException('APP_ADMIN_EMAIL is already assigned to another employee.');
    }

    if (
        $admin['email'] !== $email
        || $admin['name'] !== $name
        || !password_verify($password, $admin['password_hash'])
    ) {
        $update = $pdo->prepare('UPDATE employees SET name = ?, email = ?, password_hash = ? WHERE id = ?');
        $update->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
    }

    return (int) $admin['id'];
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirectHome(): never
{
    header('Location: /', true, 303);
    exit;
}

function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(400);
        throw new RuntimeException('Yêu cầu không hợp lệ. Vui lòng tải lại trang và thử lại.');
    }
}

function positiveId(string $field): int
{
    $id = filter_var($_POST[$field] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id === null || $id < 1) {
        throw new RuntimeException('Dữ liệu được chọn không hợp lệ.');
    }
    return $id;
}

function validText(string $value, int $maxLength): bool
{
    return preg_match('/\A.{1,' . $maxLength . '}\z/us', $value) === 1;
}

try {
    $pdo = database();
    $adminId = seedAdministrator($pdo);
} catch (InvalidArgumentException $exception) {
    error_log((string) $exception);
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo $exception->getMessage() . "\n";
    exit;
} catch (Throwable $exception) {
    error_log((string) $exception);
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Dịch vụ hiện chưa sẵn sàng. Vui lòng thử lại sau.\n";
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/metrics') {
    header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
    $today = date('Y-m-d');
    $statement = $pdo->prepare('SELECT COUNT(*) FROM session_attendance WHERE DATE(checked_in_at) = ?');
    $statement->execute([$today]);
    $todayCount = (int) $statement->fetchColumn();
    $totalCount = (int) $pdo->query('SELECT COUNT(*) FROM session_attendance')->fetchColumn();
    echo "# HELP attendance_records_total Total student attendance records.\n";
    echo "# TYPE attendance_records_total gauge\n";
    echo "attendance_records_total {$totalCount}\n";
    echo "# HELP attendance_records_today Student attendance records created for the current local date.\n";
    echo "# TYPE attendance_records_today gauge\n";
    echo "attendance_records_today {$todayCount}\n";
    exit;
}

$secureCookie = filter_var(getenv('APP_COOKIE_SECURE') ?: '0', FILTER_VALIDATE_BOOLEAN);
session_set_cookie_params([
    'httponly' => true,
    'secure' => $secureCookie,
    'samesite' => 'Strict',
    'path' => '/',
]);
session_start();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('Content-Security-Policy: default-src \'self\'; style-src \'self\' \'unsafe-inline\'; form-action \'self\'; frame-ancestors \'none\'; base-uri \'self\'');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (isset($_SESSION['employee_id'])) {
    $accountQuery = $pdo->prepare('SELECT id, name, class_name FROM employees WHERE id = ?');
    $accountQuery->execute([(int) $_SESSION['employee_id']]);
    $account = $accountQuery->fetch();
    if ($account === false) {
        $_SESSION = [];
        session_regenerate_id(true);
        redirectHome();
    }
    $_SESSION['employee_name'] = $account['name'];
    $_SESSION['class_name'] = $account['class_name'];
    $_SESSION['is_admin'] = (int) $account['id'] === $adminId;
}

$message = '';
$messageType = 'info';
$signupEnabled = filter_var(getenv('APP_SIGNUP_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'login') {
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            $query = $pdo->prepare('SELECT id, name, email, password_hash, class_name FROM employees WHERE email = ?');
            $query->execute([$email]);
            $account = $query->fetch();
            if ($account === false || !password_verify($password, $account['password_hash'])) {
                throw new RuntimeException('Email hoặc mật khẩu không chính xác.');
            }

            session_regenerate_id(true);
            $_SESSION['employee_id'] = (int) $account['id'];
            $_SESSION['employee_name'] = $account['name'];
            $_SESSION['is_admin'] = (int) $account['id'] === $adminId;
            $_SESSION['class_name'] = $account['class_name'];
            redirectHome();
        }

        if ($action === 'signup') {
            if (!$signupEnabled) {
                throw new RuntimeException('Chức năng tự đăng ký đang tắt. Vui lòng liên hệ quản trị viên.');
            }

            $name = trim((string) ($_POST['name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $className = trim((string) ($_POST['class_name'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            if (
                !validText($name, 120)
                || !filter_var($email, FILTER_VALIDATE_EMAIL)
                || !validText($className, 100)
                || strlen($password) < 12
            ) {
                throw new RuntimeException('Nhập họ tên, lớp, email hợp lệ và mật khẩu ít nhất 12 ký tự.');
            }

            $insert = $pdo->prepare('INSERT INTO employees (name, email, password_hash, class_name) VALUES (?, ?, ?, ?)');
            $insert->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $className]);
            session_regenerate_id(true);
            $_SESSION['employee_id'] = (int) $pdo->lastInsertId();
            $_SESSION['employee_name'] = $name;
            $_SESSION['is_admin'] = false;
            $_SESSION['class_name'] = $className;
            redirectHome();
        }

        if (!isset($_SESSION['employee_id'])) {
            throw new RuntimeException('Vui lòng đăng nhập để tiếp tục.');
        }

        if ($action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            redirectHome();
        }

        $isAdmin = (bool) ($_SESSION['is_admin'] ?? false);
        if ($action === 'check_in') {
            if ($isAdmin) {
                throw new RuntimeException('Tài khoản quản trị không thể điểm danh với vai trò sinh viên.');
            }

            $sessionId = positiveId('session_id');
            $sessionQuery = $pdo->prepare(
                'SELECT cs.session_date, cs.start_time, cs.late_minutes
                 FROM class_sessions cs
                 JOIN employees e ON e.id = ? AND e.class_name = cs.class_name
                 WHERE cs.id = ? AND cs.session_date = ?'
            );
            $sessionQuery->execute([(int) $_SESSION['employee_id'], $sessionId, date('Y-m-d')]);
            $classSession = $sessionQuery->fetch();
            if ($classSession === false) {
                throw new RuntimeException('Buổi học không tồn tại, không thuộc lớp của bạn hoặc không diễn ra hôm nay.');
            }

            $startsAt = new DateTimeImmutable(
                $classSession['session_date'] . ' ' . $classSession['start_time'],
                new DateTimeZone('Asia/Ho_Chi_Minh')
            );
            $lateAfter = $startsAt->modify('+' . (int) $classSession['late_minutes'] . ' minutes');
            $status = new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh')) > $lateAfter
                ? 'late'
                : 'on_time';
            $insert = $pdo->prepare(
                'INSERT INTO session_attendance (session_id, employee_id, checked_in_at, status) VALUES (?, ?, ?, ?)'
            );
            $insert->execute([
                $sessionId,
                (int) $_SESSION['employee_id'],
                date('Y-m-d H:i:s'),
                $status,
            ]);
            redirectHome();
        }

        if (!$isAdmin) {
            throw new RuntimeException('Bạn không có quyền thực hiện thao tác quản trị.');
        }

        if ($action === 'create_student' || $action === 'update_student') {
            $studentId = $action === 'update_student' ? positiveId('student_id') : null;
            if ($studentId === $adminId) {
                throw new RuntimeException('Không thể sửa tài khoản quản trị tại đây.');
            }

            $name = trim((string) ($_POST['name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $className = trim((string) ($_POST['class_name'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            if (
                !validText($name, 120)
                || !filter_var($email, FILTER_VALIDATE_EMAIL)
                || !validText($className, 100)
                || ($studentId === null && strlen($password) < 12)
                || ($password !== '' && strlen($password) < 12)
            ) {
                throw new RuntimeException('Nhập họ tên, lớp, email hợp lệ; mật khẩu phải có ít nhất 12 ký tự.');
            }

            if ($studentId === null) {
                $insert = $pdo->prepare(
                    'INSERT INTO employees (name, email, password_hash, class_name) VALUES (?, ?, ?, ?)'
                );
                $insert->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $className]);
            } elseif ($password === '') {
                $update = $pdo->prepare('UPDATE employees SET name = ?, email = ?, class_name = ? WHERE id = ?');
                $update->execute([$name, $email, $className, $studentId]);
            } else {
                $update = $pdo->prepare(
                    'UPDATE employees SET name = ?, email = ?, class_name = ?, password_hash = ? WHERE id = ?'
                );
                $update->execute([$name, $email, $className, password_hash($password, PASSWORD_DEFAULT), $studentId]);
            }
            redirectHome();
        }

        if ($action === 'delete_student') {
            $studentId = positiveId('student_id');
            if ($studentId === $adminId) {
                throw new RuntimeException('Không thể xóa tài khoản quản trị.');
            }
            $delete = $pdo->prepare('DELETE FROM employees WHERE id = ?');
            $delete->execute([$studentId]);
            redirectHome();
        }

        if ($action === 'create_subject') {
            $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
            $name = trim((string) ($_POST['name'] ?? ''));
            if (preg_match('/\A[A-Z0-9][A-Z0-9._-]{1,29}\z/', $code) !== 1 || !validText($name, 120)) {
                throw new RuntimeException('Mã môn học cần 2–30 ký tự chữ/số; tên môn học không được để trống.');
            }
            $insert = $pdo->prepare('INSERT INTO subjects (code, name) VALUES (?, ?)');
            $insert->execute([$code, $name]);
            redirectHome();
        }

        if ($action === 'create_session') {
            $subjectId = positiveId('subject_id');
            $className = trim((string) ($_POST['class_name'] ?? ''));
            $name = trim((string) ($_POST['name'] ?? ''));
            $sessionDate = (string) ($_POST['session_date'] ?? '');
            $startTime = (string) ($_POST['start_time'] ?? '');
            $endTime = (string) ($_POST['end_time'] ?? '');
            $lateMinutes = filter_var($_POST['late_minutes'] ?? null, FILTER_VALIDATE_INT);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $sessionDate);
            $validDate = $date !== false && $date->format('Y-m-d') === $sessionDate;

            if (
                !validText($className, 100)
                || !validText($name, 150)
                || !$validDate
                || preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/', $startTime) !== 1
                || preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/', $endTime) !== 1
                || $endTime <= $startTime
                || $lateMinutes === false
                || $lateMinutes < 0
                || $lateMinutes > 180
            ) {
                throw new RuntimeException('Kiểm tra lại lớp, tên buổi, ngày giờ và số phút cho phép trễ (0–180).');
            }

            $subjectQuery = $pdo->prepare('SELECT id FROM subjects WHERE id = ?');
            $subjectQuery->execute([$subjectId]);
            if ($subjectQuery->fetchColumn() === false) {
                throw new RuntimeException('Môn học đã chọn không tồn tại.');
            }
            $insert = $pdo->prepare(
                'INSERT INTO class_sessions (subject_id, class_name, name, session_date, start_time, end_time, late_minutes)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $subjectId,
                $className,
                $name,
                $sessionDate,
                $startTime . ':00',
                $endTime . ':00',
                $lateMinutes,
            ]);
            redirectHome();
        }

        if ($action === 'delete_session') {
            $sessionId = positiveId('session_id');
            $delete = $pdo->prepare('DELETE FROM class_sessions WHERE id = ?');
            $delete->execute([$sessionId]);
            redirectHome();
        }

        throw new RuntimeException('Thao tác không được hỗ trợ.');
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            $message = 'Email hoặc mã môn học đã tồn tại, hoặc sinh viên đã điểm danh buổi học này.';
        } else {
            error_log((string) $exception);
            $message = 'Không thể lưu thao tác. Vui lòng thử lại sau.';
        }
        $messageType = 'error';
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
        $messageType = 'error';
    }
}

$employeeId = isset($_SESSION['employee_id']) ? (int) $_SESSION['employee_id'] : null;
$isAdmin = (bool) ($_SESSION['is_admin'] ?? false);
$today = date('Y-m-d');
$students = [];
$subjects = [];
$sessions = [];
$recentAttendance = [];
$studentSessions = [];
$studentHistory = [];
$studentCount = 0;
$todaySessionCount = 0;
$todayAttendanceCount = 0;
$attendanceDate = $today;

if ($employeeId !== null && $isAdmin) {
    $studentsQuery = $pdo->prepare('SELECT id, name, email, class_name FROM employees WHERE id <> ? ORDER BY name, id');
    $studentsQuery->execute([$adminId]);
    $students = $studentsQuery->fetchAll();
    $studentCount = count($students);
    $subjects = $pdo->query('SELECT id, code, name FROM subjects ORDER BY code')->fetchAll();

    $todaySessionsQuery = $pdo->prepare('SELECT COUNT(*) FROM class_sessions WHERE session_date = ?');
    $todaySessionsQuery->execute([$today]);
    $todaySessionCount = (int) $todaySessionsQuery->fetchColumn();

    $todayAttendanceQuery = $pdo->prepare(
        'SELECT COUNT(*) FROM session_attendance WHERE DATE(checked_in_at) = ?'
    );
    $todayAttendanceQuery->execute([$today]);
    $todayAttendanceCount = (int) $todayAttendanceQuery->fetchColumn();

    $requestedDate = (string) ($_GET['attendance_date'] ?? $today);
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $requestedDate);
    if ($parsedDate !== false && $parsedDate->format('Y-m-d') === $requestedDate) {
        $attendanceDate = $requestedDate;
    }

    $sessionsQuery = $pdo->prepare(
        'SELECT cs.id, cs.class_name, cs.name, cs.session_date, cs.start_time, cs.end_time, cs.late_minutes,
                s.code AS subject_code, s.name AS subject_name,
                (SELECT COUNT(*) FROM session_attendance sa WHERE sa.session_id = cs.id) AS attendance_count
         FROM class_sessions cs
         JOIN subjects s ON s.id = cs.subject_id
         WHERE cs.session_date >= ?
         ORDER BY cs.session_date, cs.start_time
         LIMIT 100'
    );
    $sessionsQuery->execute([$today]);
    $sessions = $sessionsQuery->fetchAll();

    $recentQuery = $pdo->prepare(
        'SELECT sa.checked_in_at, sa.status, e.name AS student_name, e.email, e.class_name,
                cs.name AS session_name, cs.session_date, cs.start_time, s.code AS subject_code
         FROM session_attendance sa
         JOIN employees e ON e.id = sa.employee_id
         JOIN class_sessions cs ON cs.id = sa.session_id
         JOIN subjects s ON s.id = cs.subject_id
         WHERE cs.session_date = ?
         ORDER BY sa.checked_in_at DESC
         LIMIT 100'
    );
    $recentQuery->execute([$attendanceDate]);
    $recentAttendance = $recentQuery->fetchAll();
} elseif ($employeeId !== null) {
    $sessionsQuery = $pdo->prepare(
        'SELECT cs.id, cs.class_name, cs.name, cs.session_date, cs.start_time, cs.end_time, cs.late_minutes,
                s.code AS subject_code, s.name AS subject_name, sa.checked_in_at, sa.status
         FROM class_sessions cs
         JOIN subjects s ON s.id = cs.subject_id
         LEFT JOIN session_attendance sa ON sa.session_id = cs.id AND sa.employee_id = ?
         WHERE cs.class_name = ? AND cs.session_date >= ?
         ORDER BY cs.session_date, cs.start_time
         LIMIT 100'
    );
    $sessionsQuery->execute([$employeeId, (string) ($_SESSION['class_name'] ?? ''), $today]);
    $studentSessions = $sessionsQuery->fetchAll();

    $historyQuery = $pdo->prepare(
        'SELECT sa.checked_in_at, sa.status, cs.name AS session_name, cs.session_date, cs.start_time,
                cs.class_name, s.code AS subject_code, s.name AS subject_name
         FROM session_attendance sa
         JOIN class_sessions cs ON cs.id = sa.session_id
         JOIN subjects s ON s.id = cs.subject_id
         WHERE sa.employee_id = ?
         ORDER BY sa.checked_in_at DESC
         LIMIT 100'
    );
    $historyQuery->execute([$employeeId]);
    $studentHistory = $historyQuery->fetchAll();
}
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $employeeId !== null && $isAdmin ? 'Quản trị điểm danh' : 'Điểm danh lớp học' ?></title>
    <style>
        :root { color-scheme: light; font-family: "Segoe UI", Tahoma, Arial, sans-serif; color: #172033; background: #f3f6fb; }
        * { box-sizing: border-box; }
        body { margin: 0; }
        header { background: #fff; border-bottom: 1px solid #e1e8f2; padding: .9rem max(1.2rem, calc((100vw - 1320px) / 2)); display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
        header strong { font-size: 1.05rem; color: #204fc7; }
        main { max-width: 1320px; margin: 2rem auto; padding: 0 1.2rem; }
        h1 { font-size: clamp(1.65rem, 3vw, 2.2rem); margin: .35rem 0; }
        h2 { font-size: 1.2rem; margin: 0; }
        h3 { font-size: 1rem; margin: 0 0 .6rem; }
        p { line-height: 1.5; }
        .eyebrow { color: #315ab6; font-weight: 750; font-size: .82rem; letter-spacing: .12em; text-transform: uppercase; }
        .page-head { display: flex; justify-content: space-between; align-items: end; gap: 1rem; margin-bottom: 1.5rem; }
        .date-box, .card { background: white; border: 1px solid #dce4ef; border-radius: 16px; box-shadow: 0 8px 30px #173b700a; }
        .date-box { padding: .85rem 1.1rem; min-width: 160px; }
        .date-box strong { display: block; font-size: 1.1rem; margin-top: .25rem; }
        .card { padding: 1.35rem; margin-bottom: 1.2rem; }
        .stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; margin: 1rem 0 1.3rem; }
        .stat { min-height: 112px; }
        .stat span { display: block; color: #6c7a90; }
        .stat strong { display: block; font-size: 1.85rem; margin-top: .6rem; }
        .section-head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 1rem; }
        .grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; }
        .grid.two { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        label { display: block; font-weight: 650; margin: .75rem 0 .35rem; }
        input, select, button { font: inherit; border-radius: 9px; padding: .7rem .8rem; }
        input, select { width: 100%; border: 1px solid #d4deec; background: white; color: #172033; }
        input:focus, select:focus { outline: 3px solid #2057a522; border-color: #2057a5; }
        button, .button-link { border: 0; background: #2454dc; color: white; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-block; }
        button:hover, .button-link:hover { background: #173b9c; }
        button.secondary, .button-link.secondary { background: #e9eef8; color: #233650; }
        button.danger { background: #a33232; }
        .inline { display: inline; }
        .inline button { padding: .5rem .7rem; }
        .notice { padding: .85rem 1rem; border-radius: 10px; margin: 1rem 0; background: #e8f1ff; }
        .notice.error { background: #fff0ef; color: #8d2018; }
        .muted { color: #68768b; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: .75rem .55rem; border-bottom: 1px solid #e7edf5; vertical-align: middle; }
        th { color: #68768b; font-size: .86rem; white-space: nowrap; }
        td form { min-width: 90px; }
        .student-row { min-width: 850px; }
        .student-row input { min-width: 120px; padding: .5rem; }
        .student-row .name-field { min-width: 160px; }
        .session-item { border-top: 1px solid #e7edf5; padding: 1rem 0; }
        .session-item:first-of-type { border-top: 0; }
        .badge { display: inline-block; background: #eef2f8; color: #46556b; border-radius: 99px; padding: .25rem .55rem; font-size: .82rem; font-weight: 650; }
        .badge.late { background: #fff1dc; color: #8d5300; }
        .badge.present { background: #e4f5ec; color: #17653c; }
        .top-actions { display: flex; gap: .6rem; flex-wrap: wrap; }
        .date-filter { display: flex; gap: .5rem; align-items: end; }
        .date-filter label { margin: 0; }
        .empty { padding: 1rem 0; }
        @media (max-width: 760px) { .stats, .grid, .grid.two { grid-template-columns: 1fr; } .page-head { align-items: flex-start; flex-direction: column; } .date-filter { align-items: stretch; flex-direction: column; } }
        @media (max-width: 520px) { header { align-items: flex-start; flex-direction: column; } main { margin-top: 1.25rem; padding: 0 .8rem; } .card { padding: 1rem; } th, td { padding: .6rem .35rem; font-size: .9rem; } }
    </style>
</head>
<body>
<header>
    <strong>Hệ thống điểm danh lớp học</strong>
    <?php if ($employeeId !== null): ?>
        <span>
            <?= e((string) $_SESSION['employee_name']) ?> · <?= $isAdmin ? 'Quản trị viên' : 'Sinh viên' ?>
            <form class="inline" method="post">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="logout">
                <button class="secondary" type="submit">Đăng xuất</button>
            </form>
        </span>
    <?php endif; ?>
</header>
<main>
    <?php if ($employeeId === null): ?>
        <div class="page-head">
            <div><span class="eyebrow">Điểm danh trực tuyến</span><h1>Chào mừng bạn</h1><p class="muted">Đăng nhập để xem lịch học và điểm danh.</p></div>
            <div class="date-box"><span class="muted">Ngày hệ thống</span><strong><?= e(date('Y-m-d')) ?></strong></div>
        </div>
    <?php elseif ($isAdmin): ?>
        <div class="page-head">
            <div><span class="eyebrow">Trang tổng quan</span><h1>Quản trị điểm danh</h1><p class="muted">Theo dõi danh sách sinh viên và hoạt động điểm danh toàn hệ thống.</p></div>
            <div class="date-box"><span class="muted">Ngày hệ thống</span><strong><?= e($today) ?></strong></div>
        </div>
        <nav class="top-actions" aria-label="Điều hướng quản trị">
            <a class="button-link secondary" href="#students">Quản lý sinh viên</a>
            <a class="button-link secondary" href="#sessions">Quản lý buổi học</a>
            <a class="button-link secondary" href="#attendance">Bản ghi điểm danh</a>
        </nav>
    <?php else: ?>
        <div class="page-head">
            <div><span class="eyebrow">Sinh viên · <?= e((string) ($_SESSION['class_name'] ?? 'Chưa có lớp')) ?></span><h1>Lịch học và điểm danh</h1><p class="muted">Điểm danh các buổi học của lớp bạn trong ngày diễn ra.</p></div>
            <div class="date-box"><span class="muted">Ngày hệ thống</span><strong><?= e($today) ?></strong></div>
        </div>
    <?php endif; ?>

    <?php if ($message !== ''): ?>
        <div class="notice <?= e($messageType) ?>" role="alert"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($employeeId === null): ?>
        <div class="grid two">
            <section class="card">
                <h2>Đăng nhập</h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="login">
                    <label for="login-email">Email</label>
                    <input id="login-email" name="email" type="email" autocomplete="username" required>
                    <label for="login-password">Mật khẩu</label>
                    <input id="login-password" name="password" type="password" autocomplete="current-password" required>
                    <p><button type="submit">Đăng nhập</button></p>
                </form>
            </section>
            <?php if ($signupEnabled): ?>
                <section class="card">
                    <h2>Tạo tài khoản sinh viên</h2>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="signup">
                        <label for="signup-name">Họ và tên</label>
                        <input id="signup-name" name="name" maxlength="120" autocomplete="name" required>
                        <label for="signup-class">Lớp</label>
                        <input id="signup-class" name="class_name" maxlength="100" required>
                        <label for="signup-email">Email</label>
                        <input id="signup-email" name="email" type="email" autocomplete="email" required>
                        <label for="signup-password">Mật khẩu (ít nhất 12 ký tự)</label>
                        <input id="signup-password" name="password" type="password" minlength="12" autocomplete="new-password" required>
                        <p><button type="submit">Tạo tài khoản</button></p>
                    </form>
                </section>
            <?php endif; ?>
        </div>
    <?php elseif ($isAdmin): ?>
        <section class="stats">
            <article class="card stat"><span>Buổi học hôm nay</span><strong><?= $todaySessionCount ?></strong></article>
            <article class="card stat"><span>Bản ghi điểm danh hôm nay</span><strong><?= $todayAttendanceCount ?></strong></article>
            <article class="card stat"><span>Sinh viên</span><strong><?= $studentCount ?></strong></article>
        </section>

        <section class="card" id="students">
            <div class="section-head"><div><h2>Quản lý sinh viên</h2><p class="muted">Tạo tài khoản, gán lớp và cập nhật thông tin sinh viên.</p></div></div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="create_student">
                <div class="grid">
                    <div><label for="student-name">Họ và tên</label><input id="student-name" name="name" maxlength="120" required></div>
                    <div><label for="student-email">Email</label><input id="student-email" name="email" type="email" required></div>
                    <div><label for="student-class">Lớp</label><input id="student-class" name="class_name" maxlength="100" placeholder="CNTT K23A" required></div>
                </div>
                <div class="grid two">
                    <div><label for="student-password">Mật khẩu (ít nhất 12 ký tự)</label><input id="student-password" name="password" type="password" minlength="12" required></div>
                    <div class="section-head" style="align-items:end"><button type="submit">Tạo tài khoản sinh viên</button></div>
                </div>
            </form>
            <h3 style="margin-top:1.5rem">Danh sách sinh viên (<?= $studentCount ?>)</h3>
            <?php if ($students === []): ?>
                <p class="muted empty">Chưa có tài khoản sinh viên.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Họ tên</th><th>Email</th><th>Lớp</th><th>Đổi mật khẩu (không bắt buộc)</th><th>Thao tác</th></tr></thead>
                        <tbody>
                        <?php foreach ($students as $student): ?>
                            <tr class="student-row">
                                <td colspan="5">
                                    <form class="grid" method="post" style="grid-template-columns:1.1fr 1.2fr .8fr 1.2fr auto auto;align-items:end;gap:.5rem">
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="update_student">
                                        <input type="hidden" name="student_id" value="<?= (int) $student['id'] ?>">
                                        <input class="name-field" name="name" aria-label="Họ và tên" maxlength="120" value="<?= e($student['name']) ?>" required>
                                        <input name="email" aria-label="Email" type="email" value="<?= e($student['email']) ?>" required>
                                        <input name="class_name" aria-label="Lớp" maxlength="100" value="<?= e((string) ($student['class_name'] ?? '')) ?>" required>
                                        <input name="password" aria-label="Mật khẩu mới" type="password" minlength="12" placeholder="Để trống nếu giữ nguyên">
                                        <button class="secondary" type="submit">Lưu</button>
                                    </form>
                                    <form class="inline" method="post">
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="delete_student">
                                        <input type="hidden" name="student_id" value="<?= (int) $student['id'] ?>">
                                        <button class="danger" type="submit">Xóa</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="muted">Xóa sinh viên cũng xóa lịch sử điểm danh của tài khoản đó.</p>
                </div>
            <?php endif; ?>
        </section>

        <section class="card" id="sessions">
            <div class="section-head"><div><h2>Quản lý dữ liệu và buổi học</h2><p class="muted">Tạo môn học, lớp và lịch buổi học.</p></div></div>
            <form method="post" class="grid two">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="create_subject">
                <div><label for="subject-code">Mã môn học</label><input id="subject-code" name="code" maxlength="30" placeholder="ATTT202" required></div>
                <div><label for="subject-name">Tên môn học</label><input id="subject-name" name="name" maxlength="120" placeholder="An toàn thông tin" required></div>
                <div><button type="submit">Thêm môn học</button></div>
            </form>
            <hr style="border:0;border-top:1px solid #e7edf5;margin:1.4rem 0">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="create_session">
                <div class="grid">
                    <div><label for="session-subject">Môn học</label>
                        <select id="session-subject" name="subject_id" required>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= (int) $subject['id'] ?>"><?= e($subject['code'] . ' — ' . $subject['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div><label for="session-class">Lớp</label><input id="session-class" name="class_name" maxlength="100" placeholder="CNTT K23A" required></div>
                    <div><label for="session-name">Tên buổi học</label><input id="session-name" name="name" maxlength="150" placeholder="Buổi 1 - Chương mở đầu" required></div>
                    <div><label for="session-date">Ngày học</label><input id="session-date" name="session_date" type="date" value="<?= e($today) ?>" required></div>
                    <div><label for="session-start">Giờ bắt đầu</label><input id="session-start" name="start_time" type="time" value="08:00" required></div>
                    <div><label for="session-end">Giờ kết thúc</label><input id="session-end" name="end_time" type="time" value="09:30" required></div>
                    <div><label for="session-late">Cho phép trễ (phút)</label><input id="session-late" name="late_minutes" type="number" min="0" max="180" value="15" required></div>
                    <div class="section-head" style="align-items:end"><button type="submit">Tạo buổi học</button></div>
                </div>
            </form>
            <h3 style="margin-top:1.7rem">Buổi học sắp tới (tối đa 100)</h3>
            <?php if ($sessions === []): ?>
                <p class="muted empty">Chưa có buổi học sắp tới.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Ngày / giờ</th><th>Môn học</th><th>Lớp</th><th>Buổi học</th><th>Điểm danh</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($sessions as $classSession): ?>
                            <tr>
                                <td><?= e(date('d/m/Y', strtotime($classSession['session_date']))) ?><br><span class="muted"><?= e(substr($classSession['start_time'], 0, 5) . '–' . substr($classSession['end_time'], 0, 5)) ?></span></td>
                                <td><?= e($classSession['subject_code']) ?></td>
                                <td><?= e($classSession['class_name']) ?></td>
                                <td><?= e($classSession['name']) ?><br><span class="muted">Trễ sau <?= (int) $classSession['late_minutes'] ?> phút</span></td>
                                <td><?= (int) $classSession['attendance_count'] ?></td>
                                <td>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="delete_session">
                                        <input type="hidden" name="session_id" value="<?= (int) $classSession['id'] ?>">
                                        <button class="danger" type="submit">Xóa</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="muted">Xóa buổi học cũng xóa các bản ghi điểm danh của buổi đó.</p>
                </div>
            <?php endif; ?>
        </section>

        <section class="card" id="attendance">
            <div class="section-head">
                <div><h2>Bản ghi điểm danh</h2><p class="muted">Danh sách sinh viên đã điểm danh theo ngày.</p></div>
                <form method="get" class="date-filter">
                    <div><label for="attendance-date">Ngày học</label><input id="attendance-date" name="attendance_date" type="date" value="<?= e($attendanceDate) ?>"></div>
                    <button class="secondary" type="submit">Lọc</button>
                </form>
            </div>
            <?php if ($recentAttendance === []): ?>
                <p class="muted empty">Chưa có bản ghi điểm danh cho ngày này.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Giờ điểm danh</th><th>Sinh viên</th><th>Lớp</th><th>Môn học</th><th>Buổi học</th><th>Trạng thái</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentAttendance as $record): ?>
                            <tr>
                                <td><?= e(date('H:i:s', strtotime($record['checked_in_at']))) ?></td>
                                <td><?= e($record['student_name']) ?><br><span class="muted"><?= e($record['email']) ?></span></td>
                                <td><?= e((string) $record['class_name']) ?></td>
                                <td><?= e($record['subject_code']) ?></td>
                                <td><?= e($record['session_name']) ?></td>
                                <td><span class="badge <?= $record['status'] === 'late' ? 'late' : 'present' ?>"><?= $record['status'] === 'late' ? 'Đi trễ' : 'Đúng giờ' ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <section class="card">
            <h2>Buổi học của lớp hôm nay và sắp tới</h2>
            <?php if ($studentSessions === []): ?>
                <p class="muted empty">Chưa có lịch học cho lớp này.</p>
            <?php else: ?>
                <?php foreach ($studentSessions as $classSession): ?>
                    <div class="session-item">
                        <strong><?= e($classSession['subject_code'] . ' — ' . $classSession['name']) ?></strong>
                        <div class="muted"><?= e(date('d/m/Y', strtotime($classSession['session_date']))) ?> · <?= e(substr($classSession['start_time'], 0, 5) . '–' . substr($classSession['end_time'], 0, 5)) ?> · <?= e($classSession['class_name']) ?></div>
                        <?php if ($classSession['checked_in_at'] !== null): ?>
                            <p><span class="badge <?= $classSession['status'] === 'late' ? 'late' : 'present' ?>"><?= $classSession['status'] === 'late' ? 'Đã điểm danh · Đi trễ' : 'Đã điểm danh · Đúng giờ' ?></span> <span class="muted"><?= e(date('H:i:s', strtotime($classSession['checked_in_at']))) ?></span></p>
                        <?php elseif ($classSession['session_date'] === $today): ?>
                            <p><span class="muted">Cho phép trễ <?= (int) $classSession['late_minutes'] ?> phút.</span></p>
                            <form class="inline" method="post">
                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="check_in">
                                <input type="hidden" name="session_id" value="<?= (int) $classSession['id'] ?>">
                                <button type="submit">Điểm danh ngay</button>
                            </form>
                        <?php else: ?>
                            <p><span class="badge">Sắp diễn ra</span></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Lịch sử điểm danh (100 lượt gần nhất)</h2>
            <?php if ($studentHistory === []): ?>
                <p class="muted empty">Chưa có dữ liệu điểm danh.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Ngày</th><th>Môn học</th><th>Lớp</th><th>Buổi học</th><th>Giờ điểm danh</th><th>Trạng thái</th></tr></thead>
                        <tbody>
                        <?php foreach ($studentHistory as $record): ?>
                            <tr>
                                <td><?= e(date('d/m/Y', strtotime($record['session_date']))) ?></td>
                                <td><?= e($record['subject_code'] . ' — ' . $record['subject_name']) ?></td>
                                <td><?= e($record['class_name']) ?></td>
                                <td><?= e($record['session_name']) ?></td>
                                <td><?= e(date('H:i:s', strtotime($record['checked_in_at']))) ?></td>
                                <td><span class="badge <?= $record['status'] === 'late' ? 'late' : 'present' ?>"><?= $record['status'] === 'late' ? 'Đi trễ' : 'Đúng giờ' ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
