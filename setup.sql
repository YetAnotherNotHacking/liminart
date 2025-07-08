-- Setup script for pxllat database
-- Run this script to create the database and tables

-- Create database if it doesn't exist
CREATE DATABASE IF NOT EXISTS pxllat;

-- Use the database
USE pxllat;

-- Create pixels table if it doesn't exist
CREATE TABLE IF NOT EXISTS pixels (
    x INT NOT NULL,
    y INT NOT NULL,
    r TINYINT UNSIGNED NOT NULL,
    g TINYINT UNSIGNED NOT NULL,
    b TINYINT UNSIGNED NOT NULL,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ip VARCHAR(45) DEFAULT NULL,
    PRIMARY KEY (x, y)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Check if ip column exists, add it if not
SET @exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = 'pxllat'
    AND table_name = 'pixels'
    AND column_name = 'ip'
);

SET @query = IF(@exists = 0,
    'ALTER TABLE pixels ADD COLUMN ip VARCHAR(45) DEFAULT NULL',
    'SELECT "IP column already exists" AS message'
);

PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Show table structure
DESCRIBE pixels;

-- Count existing pixels (if any)
SELECT COUNT(*) AS total_pixels FROM pixels; 