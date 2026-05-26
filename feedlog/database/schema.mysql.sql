CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    created_at VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE follows (
    id INT PRIMARY KEY AUTO_INCREMENT,
    follower_id INT NOT NULL,
    followee_id INT NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    UNIQUE KEY uq_follow (follower_id, followee_id),
    FOREIGN KEY (follower_id) REFERENCES users(id),
    FOREIGN KEY (followee_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE activities (
    id INT PRIMARY KEY AUTO_INCREMENT,
    actor_id INT NOT NULL,
    type VARCHAR(20) NOT NULL,
    object_id INT,
    object_type VARCHAR(50),
    summary VARCHAR(500) NOT NULL,
    is_public TINYINT NOT NULL DEFAULT 1,
    created_at VARCHAR(32) NOT NULL,
    FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
