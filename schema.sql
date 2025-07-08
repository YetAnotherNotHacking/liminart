-- Create the pixels table
CREATE TABLE pixels (
    x SMALLINT UNSIGNED NOT NULL,
    y SMALLINT UNSIGNED NOT NULL,
    r TINYINT UNSIGNED NOT NULL,
    g TINYINT UNSIGNED NOT NULL,
    b TINYINT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    last_updated INT UNSIGNED NOT NULL,
    tile_x SMALLINT UNSIGNED GENERATED ALWAYS AS (FLOOR(x / 128)) STORED,
    tile_y SMALLINT UNSIGNED GENERATED ALWAYS AS (FLOOR(y / 128)) STORED,
    PRIMARY KEY (x, y),
    INDEX idx_tile (tile_x, tile_y)
);

-- Create the user_stats table
CREATE TABLE user_stats (
    ip_address VARCHAR(45) PRIMARY KEY,
    pixels_placed INT UNSIGNED DEFAULT 0,
    last_placed INT UNSIGNED
);

-- Create active users table
CREATE TABLE active_users (
    ip_address VARCHAR(45) PRIMARY KEY,
    last_seen INT UNSIGNED NOT NULL
);

-- Create index for performance
CREATE INDEX idx_last_updated ON pixels(last_updated);
CREATE INDEX idx_ip_address ON pixels(ip_address);

-- Create table for tracking tile updates
CREATE TABLE tile_updates (
    tile_x SMALLINT UNSIGNED NOT NULL,
    tile_y SMALLINT UNSIGNED NOT NULL,
    last_updated INT UNSIGNED NOT NULL,
    PRIMARY KEY (tile_x, tile_y)
); 