from fastapi import APIRouter, Depends, HTTPException, Request, Query, File, UploadFile
from fastapi.responses import JSONResponse, Response
from fastapi.security import HTTPBearer, HTTPAuthorizationCredentials
from sqlalchemy.ext.asyncio import AsyncSession
from database import get_db
from models import *
from services import PixelService, StatsService, AdminService, AuthService, UserService
from config import settings
import time
import psutil
import hashlib
from typing import Optional, Dict, List
from sqlalchemy import text

router = APIRouter()
security = HTTPBearer(auto_error=False)

def get_client_ip(request: Request) -> str:
    """Get client IP address"""
    forwarded = request.headers.get("X-Forwarded-For")
    if forwarded:
        return forwarded.split(",")[0].strip()
    return request.client.host

async def get_current_user(
    request: Request,
    credentials: Optional[HTTPAuthorizationCredentials] = Depends(security),
    db: AsyncSession = Depends(get_db)
) -> Optional[User]:
    """Get current user from JWT token (optional)"""
    if not credentials:
        return None
    
    try:
        user = await AuthService.get_user_by_token(db, credentials.credentials)
        return user
    except Exception:
        return None

async def get_current_user_required(
    request: Request,
    credentials: HTTPAuthorizationCredentials = Depends(security),
    db: AsyncSession = Depends(get_db)
) -> User:
    """Get current user from JWT token (required)"""
    if not credentials:
        raise HTTPException(status_code=401, detail="Authentication required")
    
    user = await AuthService.get_user_by_token(db, credentials.credentials)
    if not user:
        raise HTTPException(status_code=401, detail="Invalid authentication credentials")
    
    return user

# Canvas Operations
@router.post("/pixel", response_model=SetPixelResponse)
async def set_pixel(
    pixel_data: PixelRequest,
    request: Request,
    current_user: Optional[User] = Depends(get_current_user),
    db: AsyncSession = Depends(get_db)
):
    """Set a pixel on the canvas with checksum verification"""
    ip_address = get_client_ip(request)
    user_id = current_user.id if current_user else None
    
    success, error = await PixelService.set_pixel(db, pixel_data, ip_address, user_id)
    
    if not success:
        if error == "checksum_mismatch":
            raise HTTPException(status_code=409, detail="Checksum mismatch")
        else:
            raise HTTPException(status_code=400, detail=error)
    
    # Get updated stats
    stats = await StatsService.get_user_stats(db, ip_address, user_id)
    
    # Calculate new checksum for the tile
    tile_x = pixel_data.x // settings.tile_size
    tile_y = pixel_data.y // settings.tile_size
    new_checksum = await PixelService.calculate_tile_checksum(db, tile_x, tile_y)
    
    return SetPixelResponse(
        success=True,
        stats=stats,
        checksum=new_checksum
    )

@router.post("/pixel/raw", response_model=dict)
async def set_raw_pixel(
    pixel_data: RawPixelRequest,
    request: Request,
    db: AsyncSession = Depends(get_db)
):
    """Set a pixel without checksum or rate limiting - for bots"""
    ip_address = get_client_ip(request)
    
    success, error = await PixelService.set_raw_pixel(db, pixel_data, ip_address)
    
    if not success:
        raise HTTPException(status_code=400, detail=error)
    
    return {"success": True, "message": "Pixel set successfully"}

@router.get("/state", response_model=StateResponse)
@router.post("/state", response_model=StateResponse)
async def get_canvas_state(
    state_request: Optional[StateRequest] = None,
    tile_x: Optional[int] = Query(None),
    tile_y: Optional[int] = Query(None),
    checksum: Optional[str] = Query(None),
    db: AsyncSession = Depends(get_db)
):
    """Get canvas state/tiles"""
    # Handle both GET and POST requests
    if state_request:
        tile_x = state_request.tile_x
        tile_y = state_request.tile_y
        checksum = state_request.checksum
    
    if tile_x is None or tile_y is None:
        raise HTTPException(status_code=400, detail="tile_x and tile_y are required")
    
    # Calculate current checksum
    current_checksum = await PixelService.calculate_tile_checksum(db, tile_x, tile_y)
    checksum_match = (checksum == current_checksum) if checksum else False
    
    tiles = []
    if not checksum_match:
        # Get tile data
        pixels = await PixelService.get_tile_pixels(db, tile_x, tile_y)
        
        # Create base64 encoded tile data
        tile_data = []
        for pixel in pixels:
            tile_data.append(f"{pixel.x},{pixel.y},{pixel.r},{pixel.g},{pixel.b}")
        
        import base64
        encoded_data = base64.b64encode("|".join(tile_data).encode()).decode()
        
        tiles.append(TileData(
            tile_x=tile_x,
            tile_y=tile_y,
            data=encoded_data,
            checksum=current_checksum
        ))
    
    return StateResponse(
        canvas_width=settings.canvas_width,
        canvas_height=settings.canvas_height,
        tile_size=settings.tile_size,
        checksum_match=checksum_match,
        tiles=tiles
    )

@router.post("/updates")
async def get_updates(
    since: Optional[int] = Query(None, description="Timestamp since last update"),
    request: Request = None,
    db: AsyncSession = Depends(get_db)
):
    """Get canvas updates since timestamp (for real-time updates)"""
    try:
        # For now, return empty updates
        # In a full implementation, you'd track changes and return incremental updates
        return {
            "success": True,
            "pixels": [],  # No incremental updates yet
            "changedTiles": {},  # No tile changes yet
            "timestamp": int(time.time())
        }
    except Exception as e:
        return {
            "success": False,
            "error": str(e),
            "pixels": [],
            "changedTiles": {},
            "timestamp": int(time.time())
        }

@router.get("/stats", response_model=UserStatsResponse)
async def get_stats(
    request: Request,
    current_user: Optional[User] = Depends(get_current_user),
    db: AsyncSession = Depends(get_db)
):
    """Get user and canvas statistics"""
    ip_address = get_client_ip(request)
    user_id = current_user.id if current_user else None
    
    return await StatsService.get_user_stats(db, ip_address, user_id)

@router.get("/canvas")
async def export_canvas(
    format: str = Query("json", description="Export format: json, png, or raw"),
    db: AsyncSession = Depends(get_db)
):
    """Export canvas data"""
    try:
        if format == "json":
            # Get total pixel count for simple export
            result = await db.execute(text("SELECT COUNT(*) FROM pixels"))
            pixel_count = result.scalar() or 0
            
            return {
                "success": True,
                "format": "json",
                "pixel_count": pixel_count,
                "canvas_width": settings.canvas_width,
                "canvas_height": settings.canvas_height,
                "tile_size": settings.tile_size
            }
        else:
            raise HTTPException(status_code=400, detail="Unsupported format. Use 'json'.")
            
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Export failed: {str(e)}")

# Utility endpoints
@router.get("/ip")
async def get_ip(request: Request):
    """Get masked client IP"""
    ip = get_client_ip(request)
    # Mask last octet for privacy
    ip_parts = ip.split('.')
    if len(ip_parts) == 4:
        ip_parts[3] = 'xxx'
        masked_ip = '.'.join(ip_parts)
    else:
        masked_ip = ip[:8] + "xxx"
    
    return {"ip": masked_ip}

@router.get("/monitor", response_model=HealthResponse)
async def monitor(db: AsyncSession = Depends(get_db)):
    """System health monitoring"""
    try:
        # Test database connection
        await db.execute(text("SELECT 1"))
        db_status = "healthy"
    except Exception:
        db_status = "unhealthy"
    
    # Get system info
    memory = psutil.virtual_memory()
    uptime = time.time() - psutil.boot_time()
    
    return HealthResponse(
        status="healthy" if db_status == "healthy" else "degraded",
        uptime=uptime,
        memory_usage={
            "total": memory.total,
            "available": memory.available,
            "percent": memory.percent
        },
        database_status=db_status
    )

# Admin endpoints
@router.post("/admin/scramble")
async def admin_scramble(
    request_data: AdminScrambleRequest,
    db: AsyncSession = Depends(get_db)
):
    """Scramble canvas - admin only"""
    if request_data.password != settings.admin_password:
        raise HTTPException(status_code=403, detail="Invalid admin password")
    
    success = await AdminService.scramble_canvas(db)
    
    if success:
        return {"success": True, "message": "Canvas scrambled successfully"}
    else:
        raise HTTPException(status_code=500, detail="Failed to scramble canvas")

# Authentication endpoints
@router.post("/auth/register", response_model=dict)
async def register(
    user_data: UserCreate,
    request: Request,
    db: AsyncSession = Depends(get_db)
):
    """Register new user"""
    ip_address = get_client_ip(request)
    
    user, error = await AuthService.create_user(db, user_data, ip_address)
    
    if error:
        raise HTTPException(status_code=400, detail=error)
    
    return {
        "success": True,
        "message": "User registered successfully. Please check your email to verify your account.",
        "user_id": user.id
    }

@router.post("/auth/login", response_model=Token)
async def login(
    user_data: UserLogin,
    db: AsyncSession = Depends(get_db)
):
    """Login user"""
    user = await AuthService.authenticate_user(db, user_data.username, user_data.password)
    
    if not user:
        raise HTTPException(status_code=401, detail="Invalid username or password")
    
    # Create access token
    access_token = AuthService.create_access_token(
        data={"user_id": user.id, "username": user.username}
    )
    
    return Token(
        access_token=access_token,
        token_type="bearer",
        expires_in=settings.access_token_expire_minutes * 60
    )

@router.post("/auth/verify-email")
async def verify_email(
    verification_data: EmailVerificationRequest,
    db: AsyncSession = Depends(get_db)
):
    """Verify user email"""
    success, error = await AuthService.verify_email(db, verification_data.token)
    
    if not success:
        raise HTTPException(status_code=400, detail=error)
    
    return {"success": True, "message": "Email verified successfully"}

@router.get("/auth/me", response_model=User)
async def get_current_user_info(
    current_user: User = Depends(get_current_user_required)
):
    """Get current user information"""
    # Add profile picture flag
    user_dict = current_user.__dict__.copy()
    user_dict["has_profile_picture"] = current_user.profile_picture is not None
    
    return User(**user_dict)

# User profile endpoints
@router.get("/user/profile", response_model=User)
async def get_user_profile(
    current_user: User = Depends(get_current_user_required)
):
    """Get user profile"""
    user_dict = current_user.__dict__.copy()
    user_dict["has_profile_picture"] = current_user.profile_picture is not None
    
    return User(**user_dict)

@router.put("/user/profile")
async def update_user_profile(
    profile_data: UserProfile,
    current_user: User = Depends(get_current_user_required),
    db: AsyncSession = Depends(get_db)
):
    """Update user profile"""
    success, error = await UserService.update_profile(db, current_user.id, profile_data)
    
    if not success:
        raise HTTPException(status_code=400, detail=error)
    
    return {"success": True, "message": "Profile updated successfully"}

@router.post("/user/profile-picture")
async def upload_profile_picture(
    file: UploadFile = File(...),
    current_user: User = Depends(get_current_user_required),
    db: AsyncSession = Depends(get_db)
):
    """Upload profile picture"""
    # Read file data
    file_data = await file.read()
    
    success, error = await UserService.upload_profile_picture(
        db, current_user.id, file_data, file.content_type
    )
    
    if not success:
        raise HTTPException(status_code=400, detail=error)
    
    return {"success": True, "message": "Profile picture uploaded successfully"}

@router.get("/user/profile-picture/{user_id}")
async def get_profile_picture(
    user_id: int,
    db: AsyncSession = Depends(get_db)
):
    """Get user profile picture"""
    picture_data, content_type = await UserService.get_profile_picture(db, user_id)
    
    if not picture_data:
        raise HTTPException(status_code=404, detail="Profile picture not found")
    
    return Response(content=picture_data, media_type=content_type)

@router.get("/user/stats", response_model=UserStats)
async def get_user_statistics(
    current_user: User = Depends(get_current_user_required),
    db: AsyncSession = Depends(get_db)
):
    """Get detailed user statistics"""
    stats = await UserService.get_user_statistics(db, current_user.id)
    
    if not stats:
        raise HTTPException(status_code=404, detail="User statistics not found")
    
    return stats

@router.get("/user/search")
async def search_users(
    q: str = Query(..., min_length=3, max_length=50),
    limit: int = Query(10, ge=1, le=50),
    db: AsyncSession = Depends(get_db)
):
    """Search users by username"""
    # Simple implementation - in production you'd want full-text search
    from sqlalchemy import select, func
    from database import User
    
    result = await db.execute(
        select(User.id, User.username, User.display_name, User.total_pixels_placed, User.created_at)
        .where(User.username.ilike(f"%{q}%"))
        .where(User.is_active == True)
        .order_by(User.total_pixels_placed.desc())
        .limit(limit)
    )
    
    users = []
    for row in result.fetchall():
        users.append(UserSearchResult(
            id=row[0],
            username=row[1],
            display_name=row[2],
            total_pixels_placed=row[3],
            created_at=row[4],
            has_profile_picture=False  # You'd check this in a real implementation
        ))
    
    return {"users": users} 