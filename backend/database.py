from sqlalchemy import create_engine, Column, Integer, SmallInteger, String, DateTime, Index, text, Boolean, LargeBinary, Text
from sqlalchemy.ext.declarative import declarative_base
from sqlalchemy.orm import sessionmaker
from sqlalchemy.ext.asyncio import AsyncSession, create_async_engine
from sqlalchemy.orm import Session
from datetime import datetime
import asyncio
from config import settings

# Create async engine
engine = create_async_engine(
    settings.database_url.replace("postgresql://", "postgresql+asyncpg://"),
    echo=False
)

# Create sync engine for initial setup
sync_engine = create_engine(settings.database_url)

# Session factory
async_session = sessionmaker(engine, class_=AsyncSession, expire_on_commit=False)
SessionLocal = sessionmaker(autocommit=False, autoflush=False, bind=sync_engine)

Base = declarative_base()

class Pixel(Base):
    __tablename__ = "pixels"
    
    x = Column(SmallInteger, primary_key=True)
    y = Column(SmallInteger, primary_key=True)
    r = Column(SmallInteger, nullable=False)
    g = Column(SmallInteger, nullable=False)
    b = Column(SmallInteger, nullable=False)
    ip_address = Column(String(45), nullable=True)  # Changed to String for IPv4/IPv6
    user_id = Column(Integer, nullable=True)  # User reference
    last_updated = Column(Integer, nullable=False)
    
    # Computed tile coordinates
    tile_x = Column(SmallInteger, nullable=False)
    tile_y = Column(SmallInteger, nullable=False)
    
    def __init__(self, **kwargs):
        super().__init__(**kwargs)
        if self.x is not None and self.tile_x is None:
            self.tile_x = self.x // settings.tile_size
        if self.y is not None and self.tile_y is None:
            self.tile_y = self.y // settings.tile_size

class UserStats(Base):
    __tablename__ = "user_stats"
    
    id = Column(Integer, primary_key=True, autoincrement=True)  # Proper primary key
    ip_address = Column(String(45), nullable=True)  # Changed to String
    user_id = Column(Integer, nullable=True, unique=True)  # Unique for user stats
    pixels_placed = Column(Integer, default=0)
    last_placed = Column(Integer, nullable=True)

class ActiveUser(Base):
    __tablename__ = "active_users"
    
    id = Column(Integer, primary_key=True, autoincrement=True)  # Proper primary key
    ip_address = Column(String(45), nullable=True)  # Changed to String
    user_id = Column(Integer, nullable=True, unique=True)  # Unique for active users
    last_seen = Column(Integer, nullable=False)

class TileUpdate(Base):
    __tablename__ = "tile_updates"
    
    tile_x = Column(SmallInteger, primary_key=True)
    tile_y = Column(SmallInteger, primary_key=True)
    last_updated = Column(Integer, nullable=False)

class User(Base):
    __tablename__ = "users"
    
    id = Column(Integer, primary_key=True, index=True)
    username = Column(String(50), unique=True, index=True, nullable=False)
    email = Column(String(255), unique=True, index=True, nullable=False)
    hashed_password = Column(String(255), nullable=False)
    is_active = Column(Boolean, default=False)  # Email verification required
    is_verified = Column(Boolean, default=False)
    created_at = Column(DateTime, default=datetime.utcnow)
    last_login = Column(DateTime, nullable=True)
    
    # Profile information
    display_name = Column(String(100), nullable=True)
    bio = Column(Text, nullable=True)
    profile_picture = Column(LargeBinary, nullable=True)
    profile_picture_type = Column(String(50), nullable=True)  # MIME type
    
    # Statistics
    total_pixels_placed = Column(Integer, default=0)
    registration_ip = Column(String(45), nullable=True)  # Changed to String

class EmailVerification(Base):
    __tablename__ = "email_verifications"
    
    id = Column(Integer, primary_key=True, index=True)
    user_id = Column(Integer, nullable=False)
    token = Column(String(255), unique=True, index=True, nullable=False)
    created_at = Column(DateTime, default=datetime.utcnow)
    expires_at = Column(DateTime, nullable=False)
    used = Column(Boolean, default=False)

# Database indexes for performance
Index('idx_pixels_tile', Pixel.tile_x, Pixel.tile_y)
Index('idx_pixels_user', Pixel.user_id)
Index('idx_pixels_ip', Pixel.ip_address)
Index('idx_user_stats_user', UserStats.user_id)
Index('idx_user_stats_ip', UserStats.ip_address)
Index('idx_active_users_user', ActiveUser.user_id)
Index('idx_active_users_ip', ActiveUser.ip_address)

async def get_db():
    async with async_session() as session:
        try:
            yield session
        finally:
            await session.close()

async def init_db():
    """Initialize database tables"""
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all) 