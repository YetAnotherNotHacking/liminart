# Pixel Canvas Backend

A Python FastAPI backend with PostgreSQL database for the collaborative pixel canvas application.

## Features

- **FastAPI** - Modern, fast web framework
- **PostgreSQL** - Robust database with proper IP address types
- **Async/Await** - High-performance async operations
- **Checksum-based synchronization** - Efficient tile-based updates
- **Rate limiting** - Configurable pixel placement cooldowns
- **JWT Authentication** - Ready for future user accounts
- **Docker support** - Single container with both API and database

## API Endpoints

### Canvas Operations
- `POST /api/pixel` - Place pixel with checksum verification
- `POST /api/pixel/raw` - Place pixel without checksum (for bots)
- `GET /api/state` - Get canvas state/tiles
- `POST /api/state` - Get canvas state with checksum verification
- `GET /api/stats` - Get user and canvas statistics
- `GET /api/canvas` - Export canvas data (JSON/CSV/Binary)

### Utility
- `GET /api/ip` - Get masked client IP
- `GET /api/monitor` - System health monitoring

### Admin (Password Protected)
- `POST /api/admin/scramble` - Scramble canvas with noise
- `POST /api/admin/reset` - Reset canvas to patterns

### Future Authentication
- `POST /api/auth/register` - User registration (placeholder)
- `POST /api/auth/login` - User login (placeholder)
- `GET /api/auth/me` - Get current user (placeholder)

## Setup

### Option 1: Single Docker Container
```bash
cd backend
docker build -t pixel-canvas-backend .
docker run -p 8000:8000 pixel-canvas-backend
```

### Option 2: Docker Compose (Recommended)
```bash
cd backend
docker-compose up -d
```

### Option 3: Local Development
```bash
cd backend
pip install -r requirements.txt
# Set up PostgreSQL database
# Update .env file with database credentials
python main.py
```

## Configuration

Edit `.env` file:
```env
DATABASE_URL=postgresql://pixelcanvas:pixelcanvas@localhost/pixelcanvas
SECRET_KEY=your-secret-key-change-this-in-production
CANVAS_WIDTH=1024
CANVAS_HEIGHT=1024
TILE_SIZE=128
RATE_LIMIT_SECONDS=5
ADMIN_PASSWORD=pixeladmin
```

## Database Schema

The backend uses PostgreSQL with these tables:
- `pixels` - Main pixel storage with tile coordinates
- `user_stats` - User statistics and pixel counts
- `active_users` - Track active users
- `tile_updates` - Tile modification timestamps
- `users` - Future user accounts

## Key Features

### Checksum Synchronization
- MD5 checksums for 128x128 pixel tiles
- Client-server checksum comparison
- Only updated tiles are transmitted

### Rate Limiting
- Configurable cooldown per IP address
- Prevents spam and abuse
- Separate endpoint for bots

### Tile-Based Loading
- Efficient 128x128 pixel tiles
- Computed tile coordinates in database
- Batch updates for performance

## Frontend Integration

The backend is designed to work with your existing frontend by replicating the PHP API endpoints:

- `get_state.php` → `GET/POST /api/state`
- `set_pixel.php` → `POST /api/pixel`
- `get_stats.php` → `GET /api/stats`
- `get_raw_canvas.php` → `GET /api/canvas`
- `scramble.php` → `POST /api/admin/scramble`
- `monitor.php` → `GET /api/monitor`
- `get_ip.php` → `GET /api/ip`

## Development

The backend is structured as:
- `main.py` - FastAPI application entry point
- `api.py` - API route definitions
- `database.py` - SQLAlchemy models and database setup
- `models.py` - Pydantic request/response models
- `services.py` - Business logic and database operations
- `config.py` - Configuration management

## Future Enhancements

- JWT-based user authentication
- WebSocket support for real-time updates
- Redis caching for improved performance
- Advanced admin tools
- User roles and permissions 