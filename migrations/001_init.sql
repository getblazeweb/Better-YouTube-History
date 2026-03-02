CREATE TABLE IF NOT EXISTS watch_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    video_id TEXT NOT NULL,
    title TEXT,
    channel TEXT,
    channel_url TEXT,
    watched_at TEXT NOT NULL,
    url TEXT,
    source TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE(video_id, watched_at)
);
CREATE INDEX IF NOT EXISTS idx_watched_at ON watch_history(watched_at);
CREATE INDEX IF NOT EXISTS idx_title ON watch_history(title);
CREATE INDEX IF NOT EXISTS idx_video_id ON watch_history(video_id);

CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    ip_address TEXT NOT NULL,
    user_agent TEXT NOT NULL,
    success INTEGER NOT NULL,
    reason TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS login_settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS app_settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
