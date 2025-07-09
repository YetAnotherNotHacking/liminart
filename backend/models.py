from pydantic import BaseModel, Field, EmailStr
from typing import Optional, Dict, List
from datetime import datetime

class PixelRequest(BaseModel):
    x: int = Field(..., ge=0, le=1023)
    y: int = Field(..., ge=0, le=1023)
    r: int = Field(..., ge=0, le=255)
    g: int = Field(..., ge=0, le=255)
    b: int = Field(..., ge=0, le=255)
    checksum: Optional[str] = None

class RawPixelRequest(BaseModel):
    x: int = Field(..., ge=0, le=1023)
    y: int = Field(..., ge=0, le=1023)
    r: int = Field(..., ge=0, le=255)
    g: int = Field(..., ge=0, le=255)
    b: int = Field(..., ge=0, le=255)

class StateRequest(BaseModel):
    tile_x: int = Field(..., ge=0)
    tile_y: int = Field(..., ge=0)
    checksum: Optional[str] = None

class TileData(BaseModel):
    tile_x: int
    tile_y: int
    data: str  # Base64 encoded tile data
    checksum: str

class StateResponse(BaseModel):
    canvas_width: int
    canvas_height: int
    tile_size: int
    checksum_match: bool
    tiles: List[TileData]

class UserStatsResponse(BaseModel):
    user_pixels: int
    total_pixels: int
    active_users: int
    last_placed: Optional[int] = None
    rate_limit_remaining: Optional[int] = None

class CanvasExportRequest(BaseModel):
    format: str = Field(..., regex="^(json|csv|binary)$")
    x1: Optional[int] = Field(None, ge=0, le=1023)
    y1: Optional[int] = Field(None, ge=0, le=1023)
    x2: Optional[int] = Field(None, ge=0, le=1023)
    y2: Optional[int] = Field(None, ge=0, le=1023)

class SetPixelResponse(BaseModel):
    success: bool
    stats: UserStatsResponse
    checksum: str

class HealthResponse(BaseModel):
    status: str
    uptime: float
    memory_usage: Dict[str, float]
    database_status: str

class AdminScrambleRequest(BaseModel):
    password: str

# Authentication models
class UserCreate(BaseModel):
    username: str = Field(..., min_length=3, max_length=50, regex="^[a-zA-Z0-9_-]+$")
    email: EmailStr
    password: str = Field(..., min_length=8, max_length=128)
    display_name: Optional[str] = Field(None, max_length=100)

class UserLogin(BaseModel):
    username: str
    password: str

class Token(BaseModel):
    access_token: str
    token_type: str = "bearer"
    expires_in: int

class TokenData(BaseModel):
    username: Optional[str] = None
    user_id: Optional[int] = None

class User(BaseModel):
    id: int
    username: str
    email: str
    display_name: Optional[str] = None
    bio: Optional[str] = None
    is_active: bool
    is_verified: bool
    created_at: datetime
    last_login: Optional[datetime] = None
    total_pixels_placed: int
    has_profile_picture: bool = False

    class Config:
        from_attributes = True

class UserProfile(BaseModel):
    display_name: Optional[str] = Field(None, max_length=100)
    bio: Optional[str] = Field(None, max_length=500)

class UserStats(BaseModel):
    total_pixels_placed: int
    pixels_placed_today: int
    pixels_placed_this_week: int
    pixels_placed_this_month: int
    account_age_days: int
    last_pixel_placed: Optional[datetime] = None
    favorite_colors: List[Dict[str, int]]  # List of {color: count}
    activity_heatmap: Dict[str, int]  # Date string -> pixel count

class EmailVerificationRequest(BaseModel):
    token: str

class PasswordResetRequest(BaseModel):
    email: EmailStr

class PasswordReset(BaseModel):
    token: str
    new_password: str = Field(..., min_length=8, max_length=128)

class UserSearchResult(BaseModel):
    id: int
    username: str
    display_name: Optional[str] = None
    total_pixels_placed: int
    created_at: datetime
    has_profile_picture: bool = False 