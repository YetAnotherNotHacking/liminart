from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, func, text, delete
from sqlalchemy.orm import Session
from database import Pixel, UserStats, ActiveUser, TileUpdate, User
from models import PixelRequest, RawPixelRequest, UserStatsResponse
from config import settings
import hashlib
import time
import random
from typing import Optional, List, Dict, Tuple
import ipaddress
from passlib.context import CryptContext
from jose import JWTError, jwt
from datetime import datetime, timedelta

pwd_context = CryptContext(schemes=["bcrypt"], deprecated="auto")

class PixelService:
    @staticmethod
    async def get_pixel(db: AsyncSession, x: int, y: int) -> Optional[Pixel]:
        result = await db.execute(
            select(Pixel).where(Pixel.x == x, Pixel.y == y)
        )
        return result.scalar_one_or_none()
    
    @staticmethod
    async def set_pixel(db: AsyncSession, pixel_data: PixelRequest, ip_address: str) -> Tuple[bool, Optional[str]]:
        """Set a pixel and return success status and optional error message"""
        try:
            # Check rate limiting
            if settings.rate_limit_seconds > 0:
                rate_limit_error = await PixelService._check_rate_limit(db, ip_address)
                if rate_limit_error:
                    return False, rate_limit_error
            
            # Calculate tile coordinates
            tile_x = pixel_data.x // settings.tile_size
            tile_y = pixel_data.y // settings.tile_size
            
            # If checksum provided, verify it
            if pixel_data.checksum:
                current_checksum = await PixelService.calculate_tile_checksum(db, tile_x, tile_y)
                if current_checksum != pixel_data.checksum:
                    return False, "checksum_mismatch"
            
            timestamp = int(time.time())
            
            # Create or update pixel
            pixel = await PixelService.get_pixel(db, pixel_data.x, pixel_data.y)
            if pixel:
                pixel.r = pixel_data.r
                pixel.g = pixel_data.g
                pixel.b = pixel_data.b
                pixel.ip_address = ip_address
                pixel.last_updated = timestamp
                pixel.tile_x = tile_x
                pixel.tile_y = tile_y
            else:
                pixel = Pixel(
                    x=pixel_data.x,
                    y=pixel_data.y,
                    r=pixel_data.r,
                    g=pixel_data.g,
                    b=pixel_data.b,
                    ip_address=ip_address,
                    last_updated=timestamp,
                    tile_x=tile_x,
                    tile_y=tile_y
                )
                db.add(pixel)
            
            # Update user stats
            await PixelService._update_user_stats(db, ip_address, timestamp)
            
            # Update tile timestamp
            await PixelService._update_tile_timestamp(db, tile_x, tile_y, timestamp)
            
            await db.commit()
            return True, None
            
        except Exception as e:
            await db.rollback()
            return False, str(e)
    
    @staticmethod
    async def set_raw_pixel(db: AsyncSession, pixel_data: RawPixelRequest, ip_address: str) -> Tuple[bool, Optional[str]]:
        """Set a pixel without checksum verification - for bots"""
        try:
            # Still check rate limiting for bots
            if settings.rate_limit_seconds > 0:
                rate_limit_error = await PixelService._check_rate_limit(db, ip_address)
                if rate_limit_error:
                    return False, rate_limit_error
            
            timestamp = int(time.time())
            tile_x = pixel_data.x // settings.tile_size
            tile_y = pixel_data.y // settings.tile_size
            
            # Create or update pixel
            pixel = await PixelService.get_pixel(db, pixel_data.x, pixel_data.y)
            if pixel:
                pixel.r = pixel_data.r
                pixel.g = pixel_data.g
                pixel.b = pixel_data.b
                pixel.ip_address = ip_address
                pixel.last_updated = timestamp
                pixel.tile_x = tile_x
                pixel.tile_y = tile_y
            else:
                pixel = Pixel(
                    x=pixel_data.x,
                    y=pixel_data.y,
                    r=pixel_data.r,
                    g=pixel_data.g,
                    b=pixel_data.b,
                    ip_address=ip_address,
                    last_updated=timestamp,
                    tile_x=tile_x,
                    tile_y=tile_y
                )
                db.add(pixel)
            
            # Update user stats
            await PixelService._update_user_stats(db, ip_address, timestamp)
            
            # Update tile timestamp
            await PixelService._update_tile_timestamp(db, tile_x, tile_y, timestamp)
            
            await db.commit()
            return True, None
            
        except Exception as e:
            await db.rollback()
            return False, str(e)
    
    @staticmethod
    async def _check_rate_limit(db: AsyncSession, ip_address: str) -> Optional[str]:
        """Check if user is rate limited"""
        result = await db.execute(
            select(UserStats.last_placed).where(UserStats.ip_address == ip_address)
        )
        last_placed = result.scalar_one_or_none()
        
        if last_placed:
            time_since_last = int(time.time()) - last_placed
            if time_since_last < settings.rate_limit_seconds:
                return f"Rate limited. Wait {settings.rate_limit_seconds - time_since_last} seconds."
        
        return None
    
    @staticmethod
    async def _update_user_stats(db: AsyncSession, ip_address: str, timestamp: int):
        """Update user statistics"""
        result = await db.execute(
            select(UserStats).where(UserStats.ip_address == ip_address)
        )
        user_stats = result.scalar_one_or_none()
        
        if user_stats:
            user_stats.pixels_placed += 1
            user_stats.last_placed = timestamp
        else:
            user_stats = UserStats(
                ip_address=ip_address,
                pixels_placed=1,
                last_placed=timestamp
            )
            db.add(user_stats)
        
        # Update active users
        result = await db.execute(
            select(ActiveUser).where(ActiveUser.ip_address == ip_address)
        )
        active_user = result.scalar_one_or_none()
        
        if active_user:
            active_user.last_seen = timestamp
        else:
            active_user = ActiveUser(ip_address=ip_address, last_seen=timestamp)
            db.add(active_user)
    
    @staticmethod
    async def _update_tile_timestamp(db: AsyncSession, tile_x: int, tile_y: int, timestamp: int):
        """Update tile timestamp"""
        result = await db.execute(
            select(TileUpdate).where(TileUpdate.tile_x == tile_x, TileUpdate.tile_y == tile_y)
        )
        tile_update = result.scalar_one_or_none()
        
        if tile_update:
            tile_update.last_updated = timestamp
        else:
            tile_update = TileUpdate(tile_x=tile_x, tile_y=tile_y, last_updated=timestamp)
            db.add(tile_update)
    
    @staticmethod
    async def calculate_tile_checksum(db: AsyncSession, tile_x: int, tile_y: int) -> str:
        """Calculate MD5 checksum for a tile"""
        start_x = tile_x * settings.tile_size
        start_y = tile_y * settings.tile_size
        end_x = start_x + settings.tile_size - 1
        end_y = start_y + settings.tile_size - 1
        
        result = await db.execute(
            select(Pixel.x, Pixel.y, Pixel.r, Pixel.g, Pixel.b)
            .where(
                Pixel.x >= start_x,
                Pixel.x <= end_x,
                Pixel.y >= start_y,
                Pixel.y <= end_y
            )
            .order_by(Pixel.y, Pixel.x)
        )
        
        pixels = result.fetchall()
        pixel_data = ""
        for pixel in pixels:
            pixel_data += f"{pixel.x},{pixel.y},{pixel.r},{pixel.g},{pixel.b};"
        
        return hashlib.md5(pixel_data.encode()).hexdigest()
    
    @staticmethod
    async def get_tile_pixels(db: AsyncSession, tile_x: int, tile_y: int) -> List[Pixel]:
        """Get all pixels for a specific tile"""
        start_x = tile_x * settings.tile_size
        start_y = tile_y * settings.tile_size
        end_x = start_x + settings.tile_size - 1
        end_y = start_y + settings.tile_size - 1
        
        result = await db.execute(
            select(Pixel).where(
                Pixel.x >= start_x,
                Pixel.x <= end_x,
                Pixel.y >= start_y,
                Pixel.y <= end_y
            )
        )
        
        return result.scalars().all()
    
    @staticmethod
    async def get_updated_pixels(db: AsyncSession, since_timestamp: int) -> List[Pixel]:
        """Get pixels updated since timestamp"""
        result = await db.execute(
            select(Pixel).where(Pixel.last_updated > since_timestamp)
            .order_by(Pixel.last_updated.desc())
        )
        
        return result.scalars().all()

class StatsService:
    @staticmethod
    async def get_user_stats(db: AsyncSession, ip_address: str) -> UserStatsResponse:
        """Get user statistics"""
        result = await db.execute(
            select(UserStats.pixels_placed).where(UserStats.ip_address == ip_address)
        )
        user_pixels = result.scalar_one_or_none() or 0
        
        # Get total pixels
        result = await db.execute(
            select(func.sum(UserStats.pixels_placed))
        )
        total_pixels = result.scalar_one_or_none() or 0
        
        percentage = round((user_pixels / total_pixels) * 100, 2) if total_pixels > 0 else 0
        
        return UserStatsResponse(
            user_pixels=user_pixels,
            total_pixels=total_pixels,
            percentage=percentage
        )
    
    @staticmethod
    async def get_active_users_count(db: AsyncSession) -> int:
        """Get count of active users (last 24 hours)"""
        cutoff = int(time.time()) - 86400  # 24 hours ago
        result = await db.execute(
            select(func.count(ActiveUser.ip_address))
            .where(ActiveUser.last_seen > cutoff)
        )
        return result.scalar_one_or_none() or 0
    
    @staticmethod
    async def get_top_contributor(db: AsyncSession) -> Optional[Dict]:
        """Get top contributor"""
        result = await db.execute(
            select(UserStats.ip_address, UserStats.pixels_placed)
            .order_by(UserStats.pixels_placed.desc())
            .limit(1)
        )
        
        top_user = result.first()
        if top_user:
            # Mask IP for privacy
            ip_parts = str(top_user.ip_address).split('.')
            if len(ip_parts) == 4:
                ip_parts[3] = 'xxx'
                masked_ip = '.'.join(ip_parts)
            else:
                masked_ip = str(top_user.ip_address)[:8] + "xxx"
            
            return {
                "ip": masked_ip,
                "pixels_placed": top_user.pixels_placed
            }
        
        return None

class AdminService:
    @staticmethod
    async def scramble_canvas(db: AsyncSession) -> bool:
        """Scramble canvas with random black/white noise"""
        try:
            # Clear existing pixels
            await db.execute(delete(Pixel))
            await db.execute(delete(TileUpdate))
            
            timestamp = int(time.time())
            pixels_to_add = []
            
            # Generate random pixels
            for x in range(0, settings.canvas_width, 4):  # Every 4th pixel to avoid memory issues
                for y in range(0, settings.canvas_height, 4):
                    if random.random() < 0.3:  # 30% chance of placing a pixel
                        color = 255 if random.random() > 0.5 else 0
                        pixel = Pixel(
                            x=x, y=y, r=color, g=color, b=color,
                            ip_address="127.0.0.1", last_updated=timestamp,
                            tile_x=x // settings.tile_size,
                            tile_y=y // settings.tile_size
                        )
                        pixels_to_add.append(pixel)
            
            db.add_all(pixels_to_add)
            
            # Update tile timestamps
            tiles_to_update = set()
            for pixel in pixels_to_add:
                tiles_to_update.add((pixel.tile_x, pixel.tile_y))
            
            for tile_x, tile_y in tiles_to_update:
                tile_update = TileUpdate(tile_x=tile_x, tile_y=tile_y, last_updated=timestamp)
                db.add(tile_update)
            
            await db.commit()
            return True
            
        except Exception as e:
            await db.rollback()
            print(f"Error scrambling canvas: {e}")
            return False

# Future auth services
class AuthService:
    @staticmethod
    def verify_password(plain_password: str, hashed_password: str) -> bool:
        return pwd_context.verify(plain_password, hashed_password)
    
    @staticmethod
    def get_password_hash(password: str) -> str:
        return pwd_context.hash(password)
    
    @staticmethod
    def create_access_token(data: dict, expires_delta: Optional[timedelta] = None):
        to_encode = data.copy()
        if expires_delta:
            expire = datetime.utcnow() + expires_delta
        else:
            expire = datetime.utcnow() + timedelta(minutes=15)
        to_encode.update({"exp": expire})
        encoded_jwt = jwt.encode(to_encode, settings.secret_key, algorithm="HS256")
        return encoded_jwt 