from sqlalchemy import create_engine, Column, Integer, SmallInteger, String, DateTime, Index, text
from sqlalchemy.ext.declarative import declarative_base
from sqlalchemy.orm import sessionmaker
from sqlalchemy.dialects.postgresql import INET
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
    ip_address = Column(INET, nullable=False)
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
    
    ip_address = Column(INET, primary_key=True)
    pixels_placed = Column(Integer, default=0)
    last_placed = Column(Integer, nullable=True)

class ActiveUser(Base):
    __tablename__ = "active_users"
    
    ip_address = Column(INET, primary_key=True)
    last_seen = Column(Integer, nullable=False)

class TileUpdate(Base):
    __tablename__ = "tile_updates"
    
    tile_x = Column(SmallInteger, primary_key=True)
    tile_y = Column(SmallInteger, primary_key=True)
    last_updated = Column(Integer, nullable=False)

class User(Base):
    __tablename__ = "users"
    
    id = Column(Integer, primary_key=True, index=True)
    username = Column(String, unique=True, index=True)
    email = Column(String, unique=True, index=True)
    hashed_password = Column(String)
    is_active = Column(Integer, default=1)
    created_at = Column(DateTime, default=datetime.utcnow)

# Create indexes
Index('idx_pixels_last_updated', Pixel.last_updated)
Index('idx_pixels_tile', Pixel.tile_x, Pixel.tile_y)
Index('idx_pixels_ip', Pixel.ip_address)
Index('idx_user_stats_last_placed', UserStats.last_placed)

async def get_db():
    async with async_session() as session:
        try:
            yield session
        finally:
            await session.close()

def get_sync_db():
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()

async def init_db():
    """Initialize database tables"""
    try:
        # Use sync engine for table creation
        Base.metadata.create_all(bind=sync_engine)
        print("Database tables created successfully")
    except Exception as e:
        print(f"Error creating database tables: {e}")
        raise 