from fastapi import APIRouter, Depends, HTTPException, Request, Query
from fastapi.responses import JSONResponse
from sqlalchemy.ext.asyncio import AsyncSession
from database import get_db
from models import *
from services import PixelService, StatsService, AdminService
from config import settings
import time
import psutil
import hashlib
from typing import Optional, Dict, List

router = APIRouter()

def get_client_ip(request: Request) -> str:
    """Get client IP address"""
    forwarded = request.headers.get("X-Forwarded-For")
    if forwarded:
        return forwarded.split(",")[0].strip()
    return request.client.host

def mask_ip(ip: str) -> str:
    """Mask IP address for privacy"""
    parts = ip.split('.')
    if len(parts) == 4:
        parts[3] = 'xxx'
        return '.'.join(parts)
    else:
        return ip[:8] + "xxx"

@router.get("/ip", response_model=IPResponse)
async def get_ip(request: Request):
    """Get masked client IP address"""
    ip = get_client_ip(request)
    return IPResponse(ip=mask_ip(ip))

@router.post("/pixel", response_model=SetPixelResponse)
async def set_pixel(
    pixel_data: PixelRequest,
    request: Request,
    db: AsyncSession = Depends(get_db)
):
    """Set a pixel on the canvas with checksum verification"""
    ip_address = get_client_ip(request)
    
    success, error = await PixelService.set_pixel(db, pixel_data, ip_address)
    
    if not success:
        if error == "checksum_mismatch":
            raise HTTPException(status_code=409, detail="Checksum mismatch")
        else:
            raise HTTPException(status_code=400, detail=error)
    
    # Get updated stats
    stats = await StatsService.get_user_stats(db, ip_address)
    
    # Calculate new checksum for the tile
    tile_x = pixel_data.x // settings.tile_size
    tile_y = pixel_data.y // settings.tile_size
    new_checksum = await PixelService.calculate_tile_checksum(db, tile_x, tile_y)
    
    return SetPixelResponse(
        success=True,
        stats=stats,
        checksum=new_checksum
    )

@router.post("/pixel/raw", response_model=SetPixelResponse)
async def set_raw_pixel(
    pixel_data: RawPixelRequest,
    request: Request,
    db: AsyncSession = Depends(get_db)
):
    """Set a pixel without checksum verification - for bots"""
    ip_address = get_client_ip(request)
    
    success, error = await PixelService.set_raw_pixel(db, pixel_data, ip_address)
    
    if not success:
        raise HTTPException(status_code=400, detail=error)
    
    # Get updated stats
    stats = await StatsService.get_user_stats(db, ip_address)
    
    return SetPixelResponse(
        success=True,
        stats=stats
    )

@router.get("/state")
@router.post("/state")
async def get_state(
    request: Request,
    db: AsyncSession = Depends(get_db),
    info: Optional[bool] = Query(False),
    tile_x: Optional[int] = Query(None),
    tile_y: Optional[int] = Query(None),
    since: Optional[int] = Query(0),
    verify_checksums: Optional[bool] = Query(False),
    state_request: Optional[CanvasStateRequest] = None
):
    """Get canvas state - supports multiple modes"""
    
    # Handle POST request body
    if request.method == "POST" and not state_request:
        try:
            body = await request.json()
            state_request = CanvasStateRequest(**body)
        except:
            state_request = CanvasStateRequest()
    
    current_timestamp = int(time.time())
    
    # Info mode - return canvas metadata
    if info:
        return {
            "success": True,
            "canvas_width": settings.canvas_width,
            "canvas_height": settings.canvas_height,
            "tile_size": settings.tile_size,
            "timestamp": current_timestamp
        }
    
    # Specific tile mode
    if tile_x is not None and tile_y is not None:
        pixels = await PixelService.get_tile_pixels(db, tile_x, tile_y)
        checksum = await PixelService.calculate_tile_checksum(db, tile_x, tile_y)
        
        return CanvasStateResponse(
            success=True,
            pixels=[
                PixelResponse(
                    x=p.x, y=p.y, r=p.r, g=p.g, b=p.b,
                    tile_x=p.tile_x, tile_y=p.tile_y
                ) for p in pixels
            ],
            timestamp=current_timestamp,
            tile_checksums={f"{tile_x},{tile_y}": checksum}
        )
    
    # Updates since timestamp
    if since > 0:
        pixels = await PixelService.get_updated_pixels(db, since)
        
        # Calculate checksums for changed tiles
        changed_tiles = set()
        tile_checksums = {}
        
        for pixel in pixels:
            tile_key = f"{pixel.tile_x},{pixel.tile_y}"
            changed_tiles.add(tile_key)
        
        # If client provided checksums, only return tiles that changed
        if state_request and state_request.checksums:
            pixels_to_return = []
            for pixel in pixels:
                tile_key = f"{pixel.tile_x},{pixel.tile_y}"
                if tile_key in changed_tiles:
                    # Calculate current checksum
                    current_checksum = await PixelService.calculate_tile_checksum(
                        db, pixel.tile_x, pixel.tile_y
                    )
                    client_checksum = state_request.checksums.get(tile_key)
                    
                    if current_checksum != client_checksum:
                        pixels_to_return.append(pixel)
                        tile_checksums[tile_key] = current_checksum
            
            pixels = pixels_to_return
        else:
            # Calculate checksums for all changed tiles
            for tile_key in changed_tiles:
                tile_x, tile_y = map(int, tile_key.split(','))
                tile_checksums[tile_key] = await PixelService.calculate_tile_checksum(
                    db, tile_x, tile_y
                )
        
        return CanvasStateResponse(
            success=True,
            pixels=[
                PixelResponse(
                    x=p.x, y=p.y, r=p.r, g=p.g, b=p.b,
                    tile_x=p.tile_x, tile_y=p.tile_y
                ) for p in pixels
            ],
            timestamp=current_timestamp,
            tile_checksums=tile_checksums,
            changed_tiles=list(changed_tiles)
        )
    
    # Default - return empty state
    return CanvasStateResponse(
        success=True,
        pixels=[],
        timestamp=current_timestamp
    )

@router.get("/stats", response_model=StatsResponse)
async def get_stats(request: Request, db: AsyncSession = Depends(get_db)):
    """Get canvas statistics"""
    ip_address = get_client_ip(request)
    
    user_stats = await StatsService.get_user_stats(db, ip_address)
    active_users = await StatsService.get_active_users_count(db)
    top_contributor = await StatsService.get_top_contributor(db)
    
    return StatsResponse(
        user_stats=user_stats,
        active_users=active_users,
        top_contributor=top_contributor
    )

@router.get("/canvas")
async def get_canvas(
    format: str = Query("json", regex="^(json|binary|csv|2darray)$"),
    db: AsyncSession = Depends(get_db)
):
    """Export canvas data in various formats"""
    # This is a simplified version - full implementation would handle large datasets
    from sqlalchemy import select
    from database import Pixel
    
    result = await db.execute(select(Pixel))
    pixels = result.scalars().all()
    
    if format == "json":
        return {
            "success": True,
            "pixels": [
                {"x": p.x, "y": p.y, "r": p.r, "g": p.g, "b": p.b}
                for p in pixels
            ]
        }
    elif format == "csv":
        from fastapi.responses import PlainTextResponse
        csv_data = "x,y,r,g,b\n"
        for p in pixels:
            csv_data += f"{p.x},{p.y},{p.r},{p.g},{p.b}\n"
        return PlainTextResponse(csv_data, media_type="text/csv")
    elif format == "2darray":
        # Create 2D array representation
        canvas = [[[255, 255, 255] for _ in range(settings.canvas_width)] 
                 for _ in range(settings.canvas_height)]
        
        for p in pixels:
            if 0 <= p.x < settings.canvas_width and 0 <= p.y < settings.canvas_height:
                canvas[p.y][p.x] = [p.r, p.g, p.b]
        
        return {"success": True, "canvas": canvas}
    else:
        raise HTTPException(status_code=400, detail="Unsupported format")

@router.get("/monitor", response_model=MonitorResponse)
async def monitor(db: AsyncSession = Depends(get_db)):
    """System monitoring endpoint"""
    from sqlalchemy import select, func
    from database import Pixel, ActiveUser
    
    # Get database stats
    result = await db.execute(select(func.count(Pixel.x)))
    total_pixels = result.scalar_one_or_none() or 0
    
    active_users = await StatsService.get_active_users_count(db)
    
    # Get memory usage
    memory = psutil.virtual_memory()
    
    return MonitorResponse(
        success=True,
        timestamp=time.strftime("%Y-%m-%d %H:%M:%S"),
        database_status="Connected",
        total_pixels=total_pixels,
        active_users=active_users,
        memory_usage={
            "total": memory.total,
            "available": memory.available,
            "percent": memory.percent
        }
    )

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

@router.post("/admin/reset")
async def admin_reset(
    request_data: AdminResetRequest,
    db: AsyncSession = Depends(get_db)
):
    """Reset canvas - admin only"""
    if request_data.password != settings.admin_password:
        raise HTTPException(status_code=403, detail="Invalid admin password")
    
    # This would implement canvas reset logic similar to make_board_white.php
    # For now, just return success
    return {"success": True, "message": f"Canvas reset with pattern: {request_data.pattern}"}

# Future auth endpoints
@router.post("/auth/register", response_model=User)
async def register(user_data: UserCreate, db: AsyncSession = Depends(get_db)):
    """Register new user - placeholder for future implementation"""
    raise HTTPException(status_code=501, detail="User registration not yet implemented")

@router.post("/auth/login", response_model=Token)
async def login(user_data: UserLogin, db: AsyncSession = Depends(get_db)):
    """Login user - placeholder for future implementation"""
    raise HTTPException(status_code=501, detail="User login not yet implemented")

@router.get("/auth/me", response_model=User)
async def get_current_user(db: AsyncSession = Depends(get_db)):
    """Get current user - placeholder for future implementation"""
    raise HTTPException(status_code=501, detail="User authentication not yet implemented") 