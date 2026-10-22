CREATE TABLE IF NOT EXISTS employees (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_employees_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS shifts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shifts_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS attendance (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id BIGINT UNSIGNED NOT NULL,
    shift_id BIGINT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    checked_in_at DATETIME NULL,
    checked_out_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_employee_shift_date (employee_id, shift_id, attendance_date),
    KEY ix_attendance_date (attendance_date),
    CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id)
        REFERENCES employees (id) ON DELETE CASCADE,
    CONSTRAINT fk_attendance_shift FOREIGN KEY (shift_id)
        REFERENCES shifts (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT IGNORE INTO shifts (name, start_time, end_time) VALUES
    ('Ca sáng', '08:00:00', '12:00:00'),
    ('Ca chiều', '13:00:00', '17:00:00'),
    ('Ca tối', '18:00:00', '22:00:00');
