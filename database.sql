CREATE TABLE IF NOT EXISTS urls (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS url_checks (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    url_id BIGINT NOT NULL REFERENCES urls (id) ON DELETE CASCADE,
    status_code INTEGER,
    h1 VARCHAR(255),
    title VARCHAR(255),
    description TEXT,
    created_at TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS url_checks_url_id_created_at_id_idx
    ON url_checks (url_id, created_at DESC, id DESC);
