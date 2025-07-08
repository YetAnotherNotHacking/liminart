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
    
    class Config:
        env_file = ".env"

settings = Settings() 