# Quick Setup Guide

## Build and Run the Backend

### Option 1: Docker (Recommended)

Build the container:
```bash
cd backend
docker build -t pixel-canvas-backend .
```

Run the container:
```bash
docker run -p 9696:9696 pixel-canvas-backend
```

### Option 2: Docker Compose

```bash
cd backend
docker-compose up -d
```

### Test the API

Once running, test the endpoints:
```bash
# Install requests if needed
pip install requests

# Run the test suite
python test_api.py
```

Or manually test:
```bash
# Health check
curl http://localhost:9696/health

# Get canvas info
curl http://localhost:9696/api/state?info=true

# Place a test pixel (bot endpoint)
curl -X POST http://localhost:9696/api/pixel/raw \
  -H "Content-Type: application/json" \
  -d '{"x": 100, "y": 100, "r": 255, "g": 0, "b": 0}'

# Get stats
curl http://localhost:9696/api/stats

# Get system monitor
curl http://localhost:9696/api/monitor
```

## API Documentation

Once running, visit:
- API docs: http://localhost:9696/docs
- Alternative docs: http://localhost:9696/redoc

## Configuration

Edit `.env` file to change settings:
- Canvas size (default 1024x1024)
- Rate limiting (default 5 seconds)
- Admin password
- Database connection

## Frontend Integration

The API is designed to be a drop-in replacement for your PHP backend. Update your frontend to point to:

```javascript
// Instead of PHP files, use these endpoints:
const API_BASE = 'http://localhost:9696/api';

// get_state.php → GET/POST /api/state
// set_pixel.php → POST /api/pixel  
// Raw pixel for bots → POST /api/pixel/raw
// get_stats.php → GET /api/stats
// monitor.php → GET /api/monitor
// get_ip.php → GET /api/ip
```

## Troubleshooting

1. **Container won't start**: Check if port 9696 is available
2. **Database errors**: Container needs time to initialize PostgreSQL
3. **Permission errors**: Make sure Docker has proper permissions
4. **API errors**: Check logs with `docker logs <container_name>` 