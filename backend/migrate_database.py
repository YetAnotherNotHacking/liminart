#!/usr/bin/env python3
"""
Database migration script to fix IP address column types
This converts INET columns to VARCHAR(45) to support both IPv4 and IPv6
"""

import asyncio
import asyncpg
from config import settings

async def migrate_database():
    """Migrate database schema to use String instead of INET for IP addresses"""
    
    # Extract connection parameters from DATABASE_URL
    # Format: postgresql://user:password@host:port/database
    url_parts = settings.database_url.replace('postgresql://', '').split('@')
    user_pass = url_parts[0].split(':')
    host_db = url_parts[1].split('/')
    host_port = host_db[0].split(':')
    
    username = user_pass[0]
    password = user_pass[1] if len(user_pass) > 1 else ''
    host = host_port[0]
    port = int(host_port[1]) if len(host_port) > 1 else 5432
    database = host_db[1]
    
    conn = await asyncpg.connect(
        user=username,
        password=password,
        database=database,
        host=host,
        port=port
    )
    
    try:
        print("Starting database migration...")
        
        # Drop existing tables to recreate with new schema
        print("Dropping existing tables...")
        await conn.execute("DROP TABLE IF EXISTS active_users CASCADE;")
        await conn.execute("DROP TABLE IF EXISTS user_stats CASCADE;")
        await conn.execute("DROP TABLE IF EXISTS pixels CASCADE;")
        await conn.execute("DROP TABLE IF EXISTS tile_updates CASCADE;")
        await conn.execute("DROP TABLE IF EXISTS email_verifications CASCADE;")
        await conn.execute("DROP TABLE IF EXISTS users CASCADE;")
        
        print("Creating new tables with correct schema...")
        
        # Create users table first (referenced by other tables)
        await conn.execute("""
            CREATE TABLE users (
                id SERIAL PRIMARY KEY,
                username VARCHAR(50) UNIQUE NOT NULL,
                email VARCHAR(255) UNIQUE NOT NULL,
                hashed_password VARCHAR(255) NOT NULL,
                is_active BOOLEAN DEFAULT FALSE,
                is_verified BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_login TIMESTAMP,
                display_name VARCHAR(100),
                bio TEXT,
                profile_picture BYTEA,
                profile_picture_type VARCHAR(50),
                total_pixels_placed INTEGER DEFAULT 0,
                registration_ip VARCHAR(45)
            );
        """)
        
        # Create indexes for users
        await conn.execute("CREATE INDEX idx_users_username ON users (username);")
        await conn.execute("CREATE INDEX idx_users_email ON users (email);")
        
        # Create pixels table
        await conn.execute("""
            CREATE TABLE pixels (
                x SMALLINT NOT NULL,
                y SMALLINT NOT NULL,
                r SMALLINT NOT NULL,
                g SMALLINT NOT NULL,
                b SMALLINT NOT NULL,
                ip_address VARCHAR(45),
                user_id INTEGER REFERENCES users(id),
                last_updated INTEGER NOT NULL,
                tile_x SMALLINT NOT NULL,
                tile_y SMALLINT NOT NULL,
                PRIMARY KEY (x, y)
            );
        """)
        
        # Create indexes for pixels
        await conn.execute("CREATE INDEX idx_pixels_tile ON pixels (tile_x, tile_y);")
        await conn.execute("CREATE INDEX idx_pixels_user ON pixels (user_id);")
        await conn.execute("CREATE INDEX idx_pixels_ip ON pixels (ip_address);")
        
        # Create user_stats table
        await conn.execute("""
            CREATE TABLE user_stats (
                id SERIAL PRIMARY KEY,
                ip_address VARCHAR(45),
                user_id INTEGER UNIQUE REFERENCES users(id),
                pixels_placed INTEGER DEFAULT 0,
                last_placed INTEGER
            );
        """)
        
        # Create indexes for user_stats
        await conn.execute("CREATE INDEX idx_user_stats_user ON user_stats (user_id);")
        await conn.execute("CREATE INDEX idx_user_stats_ip ON user_stats (ip_address);")
        
        # Create active_users table
        await conn.execute("""
            CREATE TABLE active_users (
                id SERIAL PRIMARY KEY,
                ip_address VARCHAR(45),
                user_id INTEGER UNIQUE REFERENCES users(id),
                last_seen INTEGER NOT NULL
            );
        """)
        
        # Create indexes for active_users
        await conn.execute("CREATE INDEX idx_active_users_user ON active_users (user_id);")
        await conn.execute("CREATE INDEX idx_active_users_ip ON active_users (ip_address);")
        
        # Create tile_updates table
        await conn.execute("""
            CREATE TABLE tile_updates (
                tile_x SMALLINT NOT NULL,
                tile_y SMALLINT NOT NULL,
                last_updated INTEGER NOT NULL,
                PRIMARY KEY (tile_x, tile_y)
            );
        """)
        
        # Create email_verifications table
        await conn.execute("""
            CREATE TABLE email_verifications (
                id SERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id),
                token VARCHAR(255) UNIQUE NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NOT NULL,
                used BOOLEAN DEFAULT FALSE
            );
        """)
        
        # Create index for email_verifications
        await conn.execute("CREATE INDEX idx_email_verifications_token ON email_verifications (token);")
        
        print("Migration completed successfully!")
        print("All tables have been recreated with VARCHAR(45) IP address columns.")
        
    except Exception as e:
        print(f"Migration failed: {e}")
        raise
    finally:
        await conn.close()

if __name__ == "__main__":
    asyncio.run(migrate_database()) 