CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(254) NOT NULL UNIQUE,
    created_at VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE otp_codes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    code_hash VARCHAR(64) NOT NULL,
    expires_at VARCHAR(32) NOT NULL,
    used_at VARCHAR(32),
    attempt_count INT NOT NULL DEFAULT 0,
    locked_until VARCHAR(32),
    created_at VARCHAR(32) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE otp_sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    session_token_hash VARCHAR(64) NOT NULL UNIQUE,
    expires_at VARCHAR(32) NOT NULL,
    revoked_at VARCHAR(32),
    created_at VARCHAR(32) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
