CREATE TABLE users (
    id         INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(255) NOT NULL,
    created_at VARCHAR(32)  NOT NULL
) ENGINE=InnoDB;

CREATE TABLE posts (
    id         INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    author_id  INT          NOT NULL,
    content    TEXT         NOT NULL,
    created_at VARCHAR(32)  NOT NULL,
    FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE reactions (
    id         INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
    post_id    INT         NOT NULL,
    user_id    INT         NOT NULL,
    emoji      VARCHAR(32) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    UNIQUE KEY uq_reaction (post_id, user_id, emoji),
    FOREIGN KEY (post_id) REFERENCES posts(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
