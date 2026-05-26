CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE article_relations (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id   INTEGER NOT NULL,
    related_id   INTEGER NOT NULL,
    relation_type TEXT   NOT NULL,
    -- 'related' | 'sequel' | 'prequel' | 'reference'
    created_at   TEXT    NOT NULL,
    UNIQUE (article_id, related_id, relation_type),
    FOREIGN KEY (article_id) REFERENCES articles(id),
    FOREIGN KEY (related_id) REFERENCES articles(id),
    CHECK (article_id != related_id)
);
