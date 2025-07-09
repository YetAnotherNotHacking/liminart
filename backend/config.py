try:
    from pydantic_settings import BaseSettings
except ImportError:
    from pydantic import BaseSettings
from typing import Optional

class Settings(BaseSettings):
    database_url: str = "postgresql://pixelcanvas:pixelcanvas@localhost/pixelcanvas"
    secret_key: str = "your-secret-key-change-this-in-production"
    canvas_width: int = 1024
    canvas_height: int = 1024
    tile_size: int = 128
    rate_limit_seconds: int = 5
    admin_password: str = "pixeladmin"
    
    # Email configuration
    smtp_server: str = "smtp.gmail.com"
    smtp_port: int = 587
    smtp_username: str = ""
    smtp_password: str = ""
    from_email: str = ""
    
    # User system configuration
    jwt_algorithm: str = "HS256"
    access_token_expire_minutes: int = 30 * 24 * 60  # 30 days
    email_verification_expire_hours: int = 24
    max_profile_picture_size: int = 5 * 1024 * 1024  # 5MB
    frontend_url: str = "http://localhost:8080"
    
    class Config:
        env_file = ".env"

settings = Settings() 