from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, func, text, delete, and_, or_, desc, cast, String
from sqlalchemy.orm import Session
from database import Pixel, UserStats, ActiveUser, TileUpdate, User, EmailVerification
from models import PixelRequest, RawPixelRequest, UserStatsResponse, UserCreate, UserLogin, Token, UserProfile, UserStats as UserStatsModel
from config import settings
import hashlib
import time
import random
import secrets
import base64
from typing import Optional, List, Dict, Tuple
import ipaddress
from passlib.context import CryptContext
from jose import JWTError, jwt
from datetime import datetime, timedelta
from PIL import Image
import io
import magic
from email_service import EmailService

pwd_context = CryptContext(schemes=["bcrypt"], deprecated="auto")

class PixelService:
    @staticmethod
    async def get_pixel(db: AsyncSession, x: int, y: int) -> Optional[Pixel]:
        result = await db.execute(
            select(Pixel).where(Pixel.x == x, Pixel.y == y)
        )
        return result.scalar_one_or_none()
    
    @staticmethod
    async def set_pixel(db: AsyncSession, pixel_data: PixelRequest, ip_address: str, user_id: Optional[int] = None) -> Tuple[bool, Optional[str]]:
        """Set a pixel and return success status and optional error message"""
        try:
            # Check rate limiting (only for authenticated users, not IP-based for raw)
            if user_id and settings.rate_limit_seconds > 0:
                rate_limit_error = await PixelService._check_user_rate_limit(db, user_id)
                if rate_limit_error:
                    return False, rate_limit_error
            elif not user_id and settings.rate_limit_seconds > 0:
                rate_limit_error = await PixelService._check_ip_rate_limit(db, ip_address)
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
                pixel.ip_address = ip_address if not user_id else None
                pixel.user_id = user_id
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
                    ip_address=ip_address if not user_id else None,
                    user_id=user_id,
                    last_updated=timestamp,
                    tile_x=tile_x,
                    tile_y=tile_y
                )
                db.add(pixel)
            
            # Update user stats
            if user_id:
                await PixelService._update_user_stats_by_user(db, user_id, timestamp)
            else:
                await PixelService._update_user_stats_by_ip(db, ip_address, timestamp)
            
            # Update tile timestamp
            await PixelService._update_tile_timestamp(db, tile_x, tile_y, timestamp)
            
            await db.commit()
            return True, None
            
        except Exception as e:
            await db.rollback()
            return False, str(e)
    
    @staticmethod
    async def set_raw_pixel(db: AsyncSession, pixel_data: RawPixelRequest, ip_address: str) -> Tuple[bool, Optional[str]]:
        """Set a raw pixel without rate limiting or user auth - for bots"""
        try:
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
                pixel.user_id = None
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
                    user_id=None,
                    last_updated=timestamp,
                    tile_x=tile_x,
                    tile_y=tile_y
                )
                db.add(pixel)
            
            # Update tile timestamp
            await PixelService._update_tile_timestamp(db, tile_x, tile_y, timestamp)
            
            await db.commit()
            return True, None
            
        except Exception as e:
            await db.rollback()
            return False, str(e)
    
    @staticmethod
    async def _check_user_rate_limit(db: AsyncSession, user_id: int) -> Optional[str]:
        """Check if user is rate limited"""
        result = await db.execute(
            select(UserStats.last_placed).where(UserStats.user_id == user_id)
        )
        last_placed = result.scalar_one_or_none()
        
        if last_placed:
            time_since_last = int(time.time()) - last_placed
            if time_since_last < settings.rate_limit_seconds:
                return f"Rate limited. Wait {settings.rate_limit_seconds - time_since_last} seconds."
        
        return None

    @staticmethod
    async def _check_ip_rate_limit(db: AsyncSession, ip_address: str) -> Optional[str]:
        """Check if IP is rate limited"""
        result = await db.execute(
            select(UserStats.last_placed).where(cast(UserStats.ip_address, String) == ip_address)
        )
        last_placed = result.scalar_one_or_none()
        
        if last_placed:
            time_since_last = int(time.time()) - last_placed
            if time_since_last < settings.rate_limit_seconds:
                return f"Rate limited. Wait {settings.rate_limit_seconds - time_since_last} seconds."
        
        return None
    
    @staticmethod
    async def _update_user_stats_by_user(db: AsyncSession, user_id: int, timestamp: int):
        """Update user statistics by user ID"""
        result = await db.execute(
            select(UserStats).where(UserStats.user_id == user_id)
        )
        user_stats = result.scalar_one_or_none()
        
        if user_stats:
            user_stats.pixels_placed += 1
            user_stats.last_placed = timestamp
        else:
            user_stats = UserStats(
                user_id=user_id,
                ip_address=None,
                pixels_placed=1,
                last_placed=timestamp
            )
            db.add(user_stats)
        
        # Update active users
        result = await db.execute(
            select(ActiveUser).where(ActiveUser.user_id == user_id)
        )
        active_user = result.scalar_one_or_none()
        
        if active_user:
            active_user.last_seen = timestamp
        else:
            active_user = ActiveUser(user_id=user_id, ip_address=None, last_seen=timestamp)
            db.add(active_user)
        
        # Update user's total pixel count
        result = await db.execute(
            select(User).where(User.id == user_id)
        )
        user = result.scalar_one_or_none()
        if user:
            user.total_pixels_placed += 1

    @staticmethod
    async def _update_user_stats_by_ip(db: AsyncSession, ip_address: str, timestamp: int):
        """Update user statistics by IP address"""
        result = await db.execute(
            select(UserStats).where(cast(UserStats.ip_address, String) == ip_address)
        )
        user_stats = result.scalar_one_or_none()
        
        if user_stats:
            user_stats.pixels_placed += 1
            user_stats.last_placed = timestamp
        else:
            user_stats = UserStats(
                ip_address=ip_address,
                user_id=None,
                pixels_placed=1,
                last_placed=timestamp
            )
            db.add(user_stats)
        
        # Update active users
        result = await db.execute(
            select(ActiveUser).where(cast(ActiveUser.ip_address, String) == ip_address)
        )
        active_user = result.scalar_one_or_none()
        
        if active_user:
            active_user.last_seen = timestamp
        else:
            active_user = ActiveUser(ip_address=ip_address, user_id=None, last_seen=timestamp)
            db.add(active_user)

    @staticmethod
    async def _update_tile_timestamp(db: AsyncSession, tile_x: int, tile_y: int, timestamp: int):
        """Update tile modification timestamp"""
        result = await db.execute(
            select(TileUpdate).where(TileUpdate.tile_x == tile_x, TileUpdate.tile_y == tile_y)
        )
        tile_update = result.scalar_one_or_none()
        
        if tile_update:
            tile_update.last_updated = timestamp
        else:
            tile_update = TileUpdate(
                tile_x=tile_x,
                tile_y=tile_y,
                last_updated=timestamp
            )
            db.add(tile_update)

    @staticmethod
    async def get_tile_pixels(db: AsyncSession, tile_x: int, tile_y: int) -> List[Pixel]:
        """Get all pixels in a tile"""
        x_start = tile_x * settings.tile_size
        x_end = x_start + settings.tile_size
        y_start = tile_y * settings.tile_size
        y_end = y_start + settings.tile_size
        
        result = await db.execute(
            select(Pixel).where(
                and_(
                    Pixel.x >= x_start,
                    Pixel.x < x_end,
                    Pixel.y >= y_start,
                    Pixel.y < y_end
                )
            )
        )
        return result.scalars().all()

    @staticmethod
    async def calculate_tile_checksum(db: AsyncSession, tile_x: int, tile_y: int) -> str:
        """Calculate MD5 checksum for a tile"""
        pixels = await PixelService.get_tile_pixels(db, tile_x, tile_y)
        
        # Create a sorted list of pixel data for consistent checksums
        pixel_data = []
        for pixel in pixels:
            pixel_data.append(f"{pixel.x},{pixel.y},{pixel.r},{pixel.g},{pixel.b}")
        
        pixel_data.sort()
        combined_data = "|".join(pixel_data)
        
        return hashlib.md5(combined_data.encode()).hexdigest()

class StatsService:
    @staticmethod
    async def get_user_stats(db: AsyncSession, ip_address: str, user_id: Optional[int] = None) -> UserStatsResponse:
        """Get user statistics"""
        # Get user's pixel count
        if user_id:
            result = await db.execute(
                select(UserStats.pixels_placed, UserStats.last_placed).where(UserStats.user_id == user_id)
            )
        else:
            result = await db.execute(
                select(UserStats.pixels_placed, UserStats.last_placed).where(cast(UserStats.ip_address, String) == ip_address)
            )
        
        user_data = result.first()
        user_pixels = user_data[0] if user_data else 0
        last_placed = user_data[1] if user_data else None
        
        # Get total pixels on canvas
        result = await db.execute(select(func.count(Pixel.x)))
        total_pixels = result.scalar() or 0
        
        # Get active users count
        cutoff_time = int(time.time()) - 3600  # Last hour
        result = await db.execute(
            select(func.count().distinct()).select_from(ActiveUser).where(ActiveUser.last_seen > cutoff_time)
        )
        active_users = result.scalar() or 0
        
        # Calculate rate limit remaining
        rate_limit_remaining = None
        if last_placed and settings.rate_limit_seconds > 0:
            time_since_last = int(time.time()) - last_placed
            if time_since_last < settings.rate_limit_seconds:
                rate_limit_remaining = settings.rate_limit_seconds - time_since_last
        
        return UserStatsResponse(
            user_pixels=user_pixels,
            total_pixels=total_pixels,
            active_users=active_users,
            last_placed=last_placed,
            rate_limit_remaining=rate_limit_remaining
        )

class AdminService:
    @staticmethod
    async def scramble_canvas(db: AsyncSession) -> bool:
        """Scramble the canvas with random pixels"""
        try:
            # Delete all existing pixels
            await db.execute(delete(Pixel))
            
            # Generate random pixels
            pixels_to_create = []
            for _ in range(10000):  # Create 10k random pixels
                x = random.randint(0, settings.canvas_width - 1)
                y = random.randint(0, settings.canvas_height - 1)
                r = random.randint(0, 255)
                g = random.randint(0, 255)
                b = random.randint(0, 255)
                timestamp = int(time.time())
                
                pixel = Pixel(
                    x=x, y=y, r=r, g=g, b=b,
                    ip_address=None, user_id=None,
                    last_updated=timestamp,
                    tile_x=x // settings.tile_size,
                    tile_y=y // settings.tile_size
                )
                pixels_to_create.append(pixel)
            
            db.add_all(pixels_to_create)
            await db.commit()
            return True
            
        except Exception as e:
            await db.rollback()
            return False

# User Authentication Services
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
            expire = datetime.utcnow() + timedelta(minutes=settings.access_token_expire_minutes)
        to_encode.update({"exp": expire})
        encoded_jwt = jwt.encode(to_encode, settings.secret_key, algorithm=settings.jwt_algorithm)
        return encoded_jwt
    
    @staticmethod
    async def create_user(db: AsyncSession, user_data: UserCreate, ip_address: str) -> Tuple[Optional[User], Optional[str]]:
        """Create a new user account"""
        try:
            # Check if username or email already exists
            result = await db.execute(
                select(User).where(or_(User.username == user_data.username, User.email == user_data.email))
            )
            existing_user = result.scalar_one_or_none()
            
            if existing_user:
                if existing_user.username == user_data.username:
                    return None, "Username already taken"
                else:
                    return None, "Email already registered"
            
            # Create user
            hashed_password = AuthService.get_password_hash(user_data.password)
            user = User(
                username=user_data.username,
                email=user_data.email,
                hashed_password=hashed_password,
                display_name=user_data.display_name,
                registration_ip=ip_address,
                is_active=False,  # Requires email verification
                is_verified=False
            )
            
            db.add(user)
            await db.flush()  # Get the user ID
            
            # Create verification token
            verification_token = secrets.token_urlsafe(32)
            expires_at = datetime.utcnow() + timedelta(hours=settings.email_verification_expire_hours)
            
            verification = EmailVerification(
                user_id=user.id,
                token=verification_token,
                expires_at=expires_at
            )
            db.add(verification)
            
            await db.commit()
            
            # Send verification email
            await EmailService.send_verification_email(
                user.email, 
                user.username, 
                verification_token
            )
            
            return user, None
            
        except Exception as e:
            await db.rollback()
            return None, f"Registration failed: {str(e)}"
    
    @staticmethod
    async def verify_email(db: AsyncSession, token: str) -> Tuple[bool, Optional[str]]:
        """Verify user email with token"""
        try:
            # Find verification record
            result = await db.execute(
                select(EmailVerification).where(
                    and_(
                        EmailVerification.token == token,
                        EmailVerification.used == False,
                        EmailVerification.expires_at > datetime.utcnow()
                    )
                )
            )
            verification = result.scalar_one_or_none()
            
            if not verification:
                return False, "Invalid or expired verification token"
            
            # Mark as used
            verification.used = True
            
            # Activate user
            result = await db.execute(
                select(User).where(User.id == verification.user_id)
            )
            user = result.scalar_one_or_none()
            
            if user:
                user.is_active = True
                user.is_verified = True
                
                await db.commit()
                
                # Send welcome email
                await EmailService.send_welcome_email(user.email, user.username)
                
                return True, None
            else:
                return False, "User not found"
                
        except Exception as e:
            await db.rollback()
            return False, f"Verification failed: {str(e)}"
    
    @staticmethod
    async def authenticate_user(db: AsyncSession, username: str, password: str) -> Optional[User]:
        """Authenticate user login"""
        result = await db.execute(
            select(User).where(User.username == username)
        )
        user = result.scalar_one_or_none()
        
        if not user:
            return None
        if not AuthService.verify_password(password, user.hashed_password):
            return None
        if not user.is_active:
            return None
            
        # Update last login
        user.last_login = datetime.utcnow()
        await db.commit()
        
        return user
    
    @staticmethod
    async def get_user_by_token(db: AsyncSession, token: str) -> Optional[User]:
        """Get user from JWT token"""
        try:
            payload = jwt.decode(token, settings.secret_key, algorithms=[settings.jwt_algorithm])
            user_id: int = payload.get("user_id")
            if user_id is None:
                return None
        except JWTError:
            return None
        
        result = await db.execute(
            select(User).where(User.id == user_id)
        )
        return result.scalar_one_or_none()

class UserService:
    @staticmethod
    async def update_profile(db: AsyncSession, user_id: int, profile_data: UserProfile) -> Tuple[bool, Optional[str]]:
        """Update user profile"""
        try:
            result = await db.execute(
                select(User).where(User.id == user_id)
            )
            user = result.scalar_one_or_none()
            
            if not user:
                return False, "User not found"
            
            if profile_data.display_name is not None:
                user.display_name = profile_data.display_name
            if profile_data.bio is not None:
                user.bio = profile_data.bio
                
            await db.commit()
            return True, None
            
        except Exception as e:
            await db.rollback()
            return False, str(e)
    
    @staticmethod
    async def upload_profile_picture(db: AsyncSession, user_id: int, file_data: bytes, content_type: str) -> Tuple[bool, Optional[str]]:
        """Upload and process profile picture"""
        try:
            # Validate file size
            if len(file_data) > settings.max_profile_picture_size:
                return False, f"File too large. Maximum size is {settings.max_profile_picture_size / 1024 / 1024:.1f}MB"
            
            # Validate file type
            allowed_types = ["image/jpeg", "image/png", "image/gif", "image/webp"]
            if content_type not in allowed_types:
                return False, "Invalid file type. Only JPEG, PNG, GIF, and WebP are allowed"
            
            # Process image
            try:
                image = Image.open(io.BytesIO(file_data))
                
                # Convert to RGB if necessary
                if image.mode in ("RGBA", "P"):
                    image = image.convert("RGB")
                
                # Resize to reasonable dimensions
                max_size = (400, 400)
                image.thumbnail(max_size, Image.Resampling.LANCZOS)
                
                # Save as JPEG
                output = io.BytesIO()
                image.save(output, format="JPEG", quality=85)
                processed_data = output.getvalue()
                
            except Exception as e:
                return False, f"Invalid image file: {str(e)}"
            
            # Update user record
            result = await db.execute(
                select(User).where(User.id == user_id)
            )
            user = result.scalar_one_or_none()
            
            if not user:
                return False, "User not found"
            
            user.profile_picture = processed_data
            user.profile_picture_type = "image/jpeg"
            
            await db.commit()
            return True, None
            
        except Exception as e:
            await db.rollback()
            return False, str(e)
    
    @staticmethod
    async def get_profile_picture(db: AsyncSession, user_id: int) -> Tuple[Optional[bytes], Optional[str]]:
        """Get user profile picture"""
        result = await db.execute(
            select(User.profile_picture, User.profile_picture_type).where(User.id == user_id)
        )
        data = result.first()
        
        if data and data[0]:
            return data[0], data[1]
        return None, None
    
    @staticmethod
    async def get_user_statistics(db: AsyncSession, user_id: int) -> Optional[UserStatsModel]:
        """Get detailed user statistics"""
        try:
            # Get user info
            result = await db.execute(
                select(User).where(User.id == user_id)
            )
            user = result.scalar_one_or_none()
            
            if not user:
                return None
            
            # Calculate account age
            account_age_days = (datetime.utcnow() - user.created_at).days
            
            # Get pixel placement stats by time period
            now = datetime.utcnow()
            today_start = now.replace(hour=0, minute=0, second=0, microsecond=0)
            week_start = today_start - timedelta(days=7)
            month_start = today_start - timedelta(days=30)
            
            # Get pixels placed in different time periods
            pixels_today = await db.execute(
                select(func.count(Pixel.x)).where(
                    and_(
                        Pixel.user_id == user_id,
                        Pixel.last_updated >= int(today_start.timestamp())
                    )
                )
            )
            pixels_today = pixels_today.scalar() or 0
            
            pixels_week = await db.execute(
                select(func.count(Pixel.x)).where(
                    and_(
                        Pixel.user_id == user_id,
                        Pixel.last_updated >= int(week_start.timestamp())
                    )
                )
            )
            pixels_week = pixels_week.scalar() or 0
            
            pixels_month = await db.execute(
                select(func.count(Pixel.x)).where(
                    and_(
                        Pixel.user_id == user_id,
                        Pixel.last_updated >= int(month_start.timestamp())
                    )
                )
            )
            pixels_month = pixels_month.scalar() or 0
            
            # Get last pixel placed
            result = await db.execute(
                select(func.max(Pixel.last_updated)).where(Pixel.user_id == user_id)
            )
            last_pixel_timestamp = result.scalar()
            last_pixel_placed = datetime.fromtimestamp(last_pixel_timestamp) if last_pixel_timestamp else None
            
            # Get favorite colors
            result = await db.execute(
                select(Pixel.r, Pixel.g, Pixel.b, func.count().label('count'))
                .where(Pixel.user_id == user_id)
                .group_by(Pixel.r, Pixel.g, Pixel.b)
                .order_by(desc('count'))
                .limit(10)
            )
            favorite_colors = [
                {"r": row[0], "g": row[1], "b": row[2], "count": row[3]}
                for row in result.fetchall()
            ]
            
            # Generate activity heatmap (last 30 days)
            activity_heatmap = {}
            for i in range(30):
                day = today_start - timedelta(days=i)
                day_end = day + timedelta(days=1)
                
                result = await db.execute(
                    select(func.count(Pixel.x)).where(
                        and_(
                            Pixel.user_id == user_id,
                            Pixel.last_updated >= int(day.timestamp()),
                            Pixel.last_updated < int(day_end.timestamp())
                        )
                    )
                )
                count = result.scalar() or 0
                activity_heatmap[day.strftime('%Y-%m-%d')] = count
            
            return UserStatsModel(
                total_pixels_placed=user.total_pixels_placed,
                pixels_placed_today=pixels_today,
                pixels_placed_this_week=pixels_week,
                pixels_placed_this_month=pixels_month,
                account_age_days=account_age_days,
                last_pixel_placed=last_pixel_placed,
                favorite_colors=favorite_colors,
                activity_heatmap=activity_heatmap
            )
            
        except Exception as e:
            return None 