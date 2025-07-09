#!/usr/bin/env python3
"""
Database migration script to fix IP address column types.
This script will recreate all tables with VARCHAR(45) columns instead of INET.
"""

import asyncio
import asyncpg
from config import settings
import sys

async def migrate_database():
    """Migrate database schema from INET to VARCHAR for IP addresses"""
    
    # Parse the database URL
    db_url = settings.database_url
    if not db_url.startswith('postgresql://'):
        print("Error: DATABASE_URL must start with 'postgresql://'")
        sys.exit(1)
    
    try:
        # Connect to database
        conn = await asyncpg.connect(db_url)
        print("Connected to database successfully")
        
        # Check if we need to migrate
        try:
            # Check if tables exist and what their schema looks like
            result = await conn.fetch("""
                SELECT table_name, column_name, data_type 
                FROM information_schema.columns 
                WHERE table_schema = 'public' 
                AND column_name LIKE '%ip_address%'
                ORDER BY table_name, column_name;
            """)
            
            inet_columns = [row for row in result if row['data_type'] == 'inet']
            if inet_columns:
                print(f"Found {len(inet_columns)} INET columns that need migration:")
                for row in inet_columns:
                    print(f"  - {row['table_name']}.{row['column_name']}")
            else:
                print("No INET columns found - migration may not be needed")
                
        except Exception as e:
            print(f"Could not check existing schema: {e}")
            print("Proceeding with full migration...")
    
    except Exception as e:
        print(f"Database connection failed: {e}")
        print("Please ensure PostgreSQL is running and DATABASE_URL is correct")
        sys.exit(1)
    
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
        print("\nNew schema summary:")
        print("- users: user accounts with authentication and profile data")
        print("- pixels: canvas pixel data with proper IP address storage")
        print("- user_stats: user/IP statistics with proper indexing")
        print("- active_users: activity tracking with proper IP address storage")
        print("- tile_updates: tile modification timestamps")
        print("- email_verifications: email verification tokens")
        
    except Exception as e:
        print(f"Migration failed: {e}")
        raise
    finally:
        await conn.close()

if __name__ == "__main__":
    print("Pixel Canvas Database Migration")
    print("===============================")
    print("This will recreate all database tables with the correct schema.")
    print("WARNING: This will delete all existing data!")
    print()
    
    response = input("Are you sure you want to continue? (yes/no): ").strip().lower()
    if response != 'yes':
        print("Migration cancelled.")
        sys.exit(0)
    
    asyncio.run(migrate_database())
    print("\nMigration completed. You can now restart the backend service.") 