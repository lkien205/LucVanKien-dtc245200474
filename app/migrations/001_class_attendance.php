<?php
declare(strict_types=1);

function migrateClassAttendance(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS app_schema_migrations (
            version VARCHAR(64) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci"
    );

    $lock = $pdo->query("SELECT GET_LOCK('attendance_class_schema', 10)")->fetchColumn();
    if ((int) $lock !== 1) {
        throw new RuntimeException('Không thể khóa schema để cập nhật cơ sở dữ liệu.');
    }

    try {
        $migrationQuery = $pdo->prepare('SELECT 1 FROM app_schema_migrations WHERE version = ?');
        $migrationQuery->execute(['001_class_attendance']);
        if ($migrationQuery->fetchColumn() !== false) {
            return;
        }

        $classColumn = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'class_name'"
        );
        if ((int) $classColumn->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE employees ADD COLUMN class_name VARCHAR(100) NULL AFTER password_hash');
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS subjects (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                code VARCHAR(30) NOT NULL,
                name VARCHAR(120) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_subjects_code (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS class_sessions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                subject_id BIGINT UNSIGNED NOT NULL,
                class_name VARCHAR(100) NOT NULL,
                name VARCHAR(150) NOT NULL,
                session_date DATE NOT NULL,
                start_time TIME NOT NULL,
                end_time TIME NOT NULL,
                late_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY ix_class_sessions_date (session_date),
                KEY ix_class_sessions_class_date (class_name, session_date),
                CONSTRAINT fk_class_sessions_subject FOREIGN KEY (subject_id)
                    REFERENCES subjects (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS session_attendance (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                session_id BIGINT UNSIGNED NOT NULL,
                employee_id BIGINT UNSIGNED NOT NULL,
                checked_in_at DATETIME NOT NULL,
                status ENUM('on_time', 'late') NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_session_attendance_student (session_id, employee_id),
                KEY ix_session_attendance_employee (employee_id, checked_in_at),
                CONSTRAINT fk_session_attendance_session FOREIGN KEY (session_id)
                    REFERENCES class_sessions (id) ON DELETE CASCADE,
                CONSTRAINT fk_session_attendance_employee FOREIGN KEY (employee_id)
                    REFERENCES employees (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci"
        );

        $subject = $pdo->prepare('INSERT IGNORE INTO subjects (code, name) VALUES (?, ?)');
        $subject->execute(['ATTT202', 'An toàn thông tin']);

        $recordMigration = $pdo->prepare('INSERT INTO app_schema_migrations (version) VALUES (?)');
        $recordMigration->execute(['001_class_attendance']);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('attendance_class_schema')");
    }
}
