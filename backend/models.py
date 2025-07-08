from pydantic import BaseModel, Field, validator
from typing import Optional, List, Dict, Any
from datetime import datetime

class PixelRequest(BaseModel):
    x: int = Field(..., ge=0, le=1023)
    y: int = Field(..., ge=0, le=1023)
    r: int = Field(..., ge=0, le=255)
    g: int = Field(..., ge=0, le=255)
    b: int = Field(..., ge=0, le=255)
    checksum: Optional[str] = None

class RawPixelRequest(BaseModel):
    """Raw pixel request for bots - no checksum required"""
    x: int = Field(..., ge=0, le=1023)
    y: int = Field(..., ge=0, le=1023)
    r: int = Field(..., ge=0, le=255)
    g: int = Field(..., ge=0, le=255)
    b: int = Field(..., ge=0, le=255)

class PixelResponse(BaseModel):
    x: int
    y: int
    r: int
    g: int
    b: int
    tile_x: int
    tile_y: int

class UserStatsResponse(BaseModel):
    user_pixels: int
    total_pixels: int
    percentage: float

class CanvasStateRequest(BaseModel):
    checksums: Optional[Dict[str, str]] = None

class CanvasStateResponse(BaseModel):
    success: bool
    pixels: List[PixelResponse]
    timestamp: int
    tile_checksums: Optional[Dict[str, str]] = None
    changed_tiles: Optional[List[str]] = None

class SetPixelResponse(BaseModel):
    success: bool
    stats: UserStatsResponse
    checksum: Optional[str] = None

class StatsResponse(BaseModel):
    user_stats: UserStatsResponse
    active_users: int
    top_contributor: Optional[Dict[str, Any]] = None

class AdminScrambleRequest(BaseModel):
    password: str

class AdminResetRequest(BaseModel):
    password: str
    pattern: str = "white"  # white, checker, noise
    size: int = 10
    color: str = "#FFFFFF"
    alt_color: str = "#F0F0F0"

class MonitorResponse(BaseModel):
    success: bool
    timestamp: str
    database_status: str
    total_pixels: int
    active_users: int
    memory_usage: Dict[str, Any]

class IPResponse(BaseModel):
    ip: str

# Future auth models
class UserCreate(BaseModel):
    username: str = Field(..., min_length=3, max_length=50)
    email: str = Field(..., regex=r'^[^@]+@[^@]+\.[^@]+$')
    password: str = Field(..., min_length=6)

class UserLogin(BaseModel):
    username: str
    password: str

class Token(BaseModel):
    access_token: str
    token_type: str

class TokenData(BaseModel):
    username: Optional[str] = None

class User(BaseModel):
    id: int
    username: str
    email: str
    is_active: bool
    created_at: datetime

    class Config:
        from_attributes = True 