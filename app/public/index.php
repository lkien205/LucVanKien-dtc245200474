<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Ho_Chi_Minh');

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

    return $pdo;
}

function seedAdministrator(PDO $pdo): void
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
        return;
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
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(400);
        throw new RuntimeException('Yêu cầu không hợp lệ. Vui lòng tải lại trang và thử lại.');
    }
}

try {
    $pdo = database();
    seedAdministrator($pdo);
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
    $statement = $pdo->prepare('SELECT COUNT(*) FROM attendance WHERE attendance_date = ?');
    $statement->execute([$today]);
    $todayCount = (int) $statement->fetchColumn();
    $totalCount = (int) $pdo->query('SELECT COUNT(*) FROM attendance')->fetchColumn();
    echo "# HELP attendance_records_total Total attendance records.\n";
    echo "# TYPE attendance_records_total gauge\n";
    echo "attendance_records_total {$totalCount}\n";
    echo "# HELP attendance_records_today Attendance records created for the current local date.\n";
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

$message = '';
$messageType = 'info';
$signupEnabled = filter_var(getenv('APP_SIGNUP_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'signup') {
            if (!$signupEnabled) {
                throw new RuntimeException('Chức năng tạo tài khoản đang tắt. Vui lòng liên hệ quản trị viên.');
            }
            $name = trim((string) ($_POST['name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');

            if (preg_match('/\A.{1,120}\z/us', $name) !== 1 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
                throw new RuntimeException('Nhập tên hợp lệ, email hợp lệ và mật khẩu ít nhất 12 ký tự.');
            }
            $insert = $pdo->prepare('INSERT INTO employees (name, email, password_hash) VALUES (?, ?, ?)');
            $insert->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['employee_id'] = (int) $pdo->lastInsertId();
            $_SESSION['employee_name'] = $name;
            redirectHome();
        }

        if ($action === 'login') {
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            $query = $pdo->prepare('SELECT id, name, password_hash FROM employees WHERE email = ?');
            $query->execute([$email]);
            $employee = $query->fetch();

            if ($employee === false || !password_verify($password, $employee['password_hash'])) {
                throw new RuntimeException('Email hoặc mật khẩu không chính xác.');
            }
            session_regenerate_id(true);
            $_SESSION['employee_id'] = (int) $employee['id'];
            $_SESSION['employee_name'] = $employee['name'];
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

        if ($action === 'check_in' || $action === 'check_out') {
            $shiftId = filter_var($_POST['shift_id'] ?? null, FILTER_VALIDATE_INT);
            if ($shiftId === false || $shiftId === null || $shiftId < 1) {
                throw new RuntimeException('Vui lòng chọn ca làm hợp lệ.');
            }

            $shiftQuery = $pdo->prepare('SELECT id FROM shifts WHERE id = ?');
            $shiftQuery->execute([$shiftId]);
            if ($shiftQuery->fetch() === false) {
                throw new RuntimeException('Ca làm không tồn tại.');
            }

            $employeeId = (int) $_SESSION['employee_id'];
            $today = date('Y-m-d');
            if ($action === 'check_in') {
                $insert = $pdo->prepare(
                    'INSERT INTO attendance (employee_id, shift_id, attendance_date, checked_in_at) VALUES (?, ?, ?, ?)'
                );
                $insert->execute([$employeeId, $shiftId, $today, date('Y-m-d H:i:s')]);
            } else {
                $update = $pdo->prepare(
                    'UPDATE attendance SET checked_out_at = ? WHERE employee_id = ? AND shift_id = ? AND attendance_date = ? AND checked_in_at IS NOT NULL AND checked_out_at IS NULL'
                );
                $update->execute([date('Y-m-d H:i:s'), $employeeId, $shiftId, $today]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('Không tìm thấy lượt vào ca chưa kết thúc cho ca này hôm nay.');
                }
            }
            redirectHome();
        }

        throw new RuntimeException('Thao tác không được hỗ trợ.');
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            $message = 'Email này đã được đăng ký, hoặc bạn đã điểm danh ca này hôm nay.';
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
$shifts = $pdo->query('SELECT id, name, start_time, end_time FROM shifts ORDER BY start_time')->fetchAll();
$todayAttendance = [];
$history = [];

if ($employeeId !== null) {
    $todayQuery = $pdo->prepare(
        'SELECT a.shift_id, a.checked_in_at, a.checked_out_at FROM attendance a WHERE a.employee_id = ? AND a.attendance_date = ?'
    );
    $todayQuery->execute([$employeeId, date('Y-m-d')]);
    foreach ($todayQuery->fetchAll() as $record) {
        $todayAttendance[(int) $record['shift_id']] = $record;
    }

    $historyQuery = $pdo->prepare(
        'SELECT a.attendance_date, a.checked_in_at, a.checked_out_at, s.name AS shift_name FROM attendance a JOIN shifts s ON s.id = a.shift_id WHERE a.employee_id = ? ORDER BY a.attendance_date DESC, a.checked_in_at DESC LIMIT 100'
    );
    $historyQuery->execute([$employeeId]);
    $history = $historyQuery->fetchAll();
}
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Điểm danh nhân viên</title>
    <style>
        :root { color-scheme: light; font-family: "Segoe UI", Tahoma, Arial, sans-serif; color: #172033; background: #f3f6fb; }
        * { box-sizing: border-box; }
        body { margin: 0; }
        header { background: #173b70; color: white; padding: 1rem max(1rem, calc((100vw - 960px) / 2)); display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
        main { max-width: 960px; margin: 2rem auto; padding: 0 1rem; }
        h1 { font-size: 1.7rem; margin: 0 0 1rem; }
        h2 { font-size: 1.2rem; margin-top: 0; }
        .card { background: white; border: 1px solid #dce4ef; border-radius: 12px; padding: 1.25rem; margin-bottom: 1rem; box-shadow: 0 5px 20px #173b700d; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1rem; }
        label { display: block; font-weight: 600; margin: .75rem 0 .3rem; }
        input, select, button { font: inherit; border-radius: 7px; padding: .65rem .75rem; }
        input, select { width: 100%; border: 1px solid #aebbd0; background: white; }
        button { border: 0; background: #2057a5; color: white; font-weight: 650; cursor: pointer; }
        button:hover { background: #173b70; }
        button.secondary { background: #59677d; }
        .inline { display: inline; }
        .inline button { padding: .45rem .7rem; }
        .notice { padding: .8rem 1rem; border-radius: 8px; margin-bottom: 1rem; background: #e8f1ff; }
        .notice.error { background: #fff0ef; color: #8d2018; }
        .muted { color: #5b6678; }
        .shift { border-top: 1px solid #e5eaf1; padding: .8rem 0; }
        .shift:first-of-type { border-top: 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: .7rem .5rem; border-bottom: 1px solid #e5eaf1; }
        .table-wrap { overflow-x: auto; }
        @media (max-width: 520px) { header { align-items: flex-start; } th, td { padding: .55rem .3rem; font-size: .9rem; } }
    </style>
</head>
<body>
<header>
    <strong>Hệ thống điểm danh</strong>
    <?php if ($employeeId !== null): ?>
        <span>Xin chào, <?= e((string) $_SESSION['employee_name']) ?>
            <form class="inline" method="post">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="logout">
                <button class="secondary" type="submit">Đăng xuất</button>
            </form>
        </span>
    <?php endif; ?>
</header>
<main>
    <h1>Điểm danh nhân viên</h1>
    <p class="muted">Hôm nay: <?= e(date('d/m/Y')) ?></p>
    <?php if ($message !== ''): ?>
        <div class="notice <?= e($messageType) ?>" role="alert"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($employeeId === null): ?>
        <div class="grid">
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
                    <h2>Tạo tài khoản nhân viên</h2>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="signup">
                        <label for="signup-name">Họ và tên</label>
                        <input id="signup-name" name="name" maxlength="120" autocomplete="name" required>
                        <label for="signup-email">Email</label>
                        <input id="signup-email" name="email" type="email" autocomplete="email" required>
                        <label for="signup-password">Mật khẩu (ít nhất 12 ký tự)</label>
                        <input id="signup-password" name="password" type="password" minlength="12" autocomplete="new-password" required>
                        <p><button type="submit">Tạo tài khoản</button></p>
                    </form>
                </section>
            <?php else: ?>
                <section class="card">
                    <h2>Tạo tài khoản nhân viên</h2>
                    <p class="muted">Chức năng đăng ký đang tắt. Vui lòng liên hệ quản trị viên.</p>
                </section>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <section class="card">
            <h2>Ca làm hôm nay</h2>
            <?php foreach ($shifts as $shift): ?>
                <?php $record = $todayAttendance[(int) $shift['id']] ?? null; ?>
                <div class="shift">
                    <strong><?= e($shift['name']) ?></strong>
                    <span class="muted">(<?= e(substr($shift['start_time'], 0, 5)) ?>–<?= e(substr($shift['end_time'], 0, 5)) ?>)</span>
                    <?php if ($record === null): ?>
                        <span class="muted">Chưa điểm danh vào.</span>
                        <form class="inline" method="post">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="check_in">
                            <input type="hidden" name="shift_id" value="<?= (int) $shift['id'] ?>">
                            <button type="submit">Điểm danh vào</button>
                        </form>
                    <?php elseif ($record['checked_out_at'] === null): ?>
                        <span>Vào: <?= e((string) $record['checked_in_at']) ?></span>
                        <form class="inline" method="post">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="check_out">
                            <input type="hidden" name="shift_id" value="<?= (int) $shift['id'] ?>">
                            <button type="submit">Điểm danh ra</button>
                        </form>
                    <?php else: ?>
                        <span>Vào: <?= e((string) $record['checked_in_at']) ?> · Ra: <?= e((string) $record['checked_out_at']) ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>
        <section class="card">
            <h2>Lịch sử điểm danh (tối đa 100 lượt gần nhất)</h2>
            <?php if ($history === []): ?>
                <p class="muted">Chưa có dữ liệu điểm danh.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Ngày</th><th>Ca</th><th>Giờ vào</th><th>Giờ ra</th></tr></thead>
                        <tbody>
                        <?php foreach ($history as $record): ?>
                            <tr>
                                <td><?= e(date('d/m/Y', strtotime($record['attendance_date']))) ?></td>
                                <td><?= e($record['shift_name']) ?></td>
                                <td><?= e($record['checked_in_at'] ?? '—') ?></td>
                                <td><?= e($record['checked_out_at'] ?? '—') ?></td>
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
