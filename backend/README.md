# Pixel Canvas Backend

A Python FastAPI backend with PostgreSQL database for the collaborative pixel canvas application, featuring comprehensive user authentication, email verification, profile management, and enhanced statistics.

## 🚀 Features

### Core Canvas Features
- **FastAPI** - Modern, fast web framework with automatic API documentation
- **PostgreSQL** - Robust database with proper IP address types and full-text search
- **Async/Await** - High-performance async operations
- **Checksum-based synchronization** - Efficient tile-based updates
- **Docker support** - Single container with both API and database

### 🔐 User Authentication System
- **User Registration** - Complete signup flow with email verification
- **Beautiful Email Templates** - HTML emails with pixel art themes
- **JWT Authentication** - Secure token-based authentication (30-day expiry)
- **Email Verification** - Required email verification before account activation
- **Profile Management** - Custom display names, bios, and profile pictures
- **Profile Pictures** - Up to 5MB image uploads with automatic processing

### 📊 Advanced Statistics
- **User-specific rate limiting** - Rate limits tied to user accounts, not just IP
- **Detailed user statistics** - Pixels placed by time periods, favorite colors
- **Activity heatmaps** - 30-day pixel placement activity visualization
- **User search** - Find other users and view their profiles
- **Account age tracking** - Days since account creation

### 🎨 Canvas Operations
- **Authenticated pixel placement** - Rate limiting per user account
- **Raw pixel placement** - Bot-friendly endpoint without rate limits
- **IP-based fallback** - Anonymous users can still place pixels with IP rate limiting

## 📡 API Endpoints

### Canvas Operations
- `POST /api/pixel` - Place pixel (authenticated users get user-based rate limiting)
- `POST /api/pixel/raw` - Place pixel without rate limits (for bots)
- `GET /api/state` - Get canvas state/tiles with checksum verification
- `POST /api/state` - Get canvas state with tile data
- `GET /api/stats` - Get user and canvas statistics
- `GET /api/canvas` - Export canvas data (placeholder)

### 🔑 Authentication
- `POST /api/auth/register` - Register new user with email verification
- `POST /api/auth/login` - Login and get JWT token
- `POST /api/auth/verify-email` - Verify email with token
- `GET /api/auth/me` - Get current user information

### 👤 User Profile Management
- `GET /api/user/profile` - Get user profile
- `PUT /api/user/profile` - Update display name and bio
- `POST /api/user/profile-picture` - Upload profile picture (5MB max)
- `GET /api/user/profile-picture/{user_id}` - Get user's profile picture
- `GET /api/user/stats` - Get detailed user statistics
- `GET /api/user/search?q=username` - Search users by username

### 🛠️ Utility & Admin
- `GET /api/ip` - Get masked client IP
- `GET /api/monitor` - System health monitoring
- `POST /api/admin/scramble` - Scramble canvas (admin only)

## ⚙️ Setup

### Quick Start with Docker Compose (Recommended)
```bash
cd backend
cp env.example .env
# Edit .env with your email settings
docker-compose up -d
```

### Single Docker Container
```bash
cd backend
docker build -t pixel-canvas-backend .
docker run -p 9696:9696 pixel-canvas-backend
```

### Local Development
```bash
cd backend
pip install -r requirements.txt
# Set up PostgreSQL database
# Copy env.example to .env and configure
python main.py
```

## 🔧 Configuration

Create a `.env` file (copy from `env.example`):

```env
# Database Configuration
DATABASE_URL=postgresql://pixelcanvas:pixelcanvas@localhost/pixelcanvas

# Security
SECRET_KEY=your-super-secret-key-change-this-in-production
ADMIN_PASSWORD=pixeladmin

# Canvas Settings
CANVAS_WIDTH=1024
CANVAS_HEIGHT=1024
TILE_SIZE=128
RATE_LIMIT_SECONDS=5

# Email Configuration (Required for user registration)
SMTP_SERVER=smtp.gmail.com
SMTP_PORT=587
SMTP_USERNAME=your-email@gmail.com
SMTP_PASSWORD=your-app-password
FROM_EMAIL=noreply@yourpixelcanvas.com

# JWT Configuration
JWT_ALGORITHM=HS256
ACCESS_TOKEN_EXPIRE_MINUTES=43200  # 30 days
EMAIL_VERIFICATION_EXPIRE_HOURS=24

# File Upload Settings
MAX_PROFILE_PICTURE_SIZE=5242880  # 5MB in bytes

# Frontend URL (for email links)
FRONTEND_URL=http://localhost:8080
```

### 📧 Email Setup Guide

#### Gmail Setup
1. Enable 2FA on your Google account
2. Generate an App Password: Google Account → Security → App Passwords
3. Use the app password in `SMTP_PASSWORD`

#### Other Email Providers
- **Outlook**: `smtp-mail.outlook.com:587`
- **Yahoo**: `smtp.mail.yahoo.com:587` 
- **Custom SMTP**: Configure your own SMTP server

## 📊 Database Schema

### Users Table
- `id` - Primary key
- `username` - Unique username (3-50 chars, alphanumeric + _ -)
- `email` - Unique email address
- `hashed_password` - Bcrypt hashed password
- `display_name` - Optional display name (max 100 chars)
- `bio` - Optional bio (max 500 chars)
- `profile_picture` - Binary profile picture data (5MB max)
- `is_active` - Email verified flag
- `is_verified` - Account verification status
- `total_pixels_placed` - Cached pixel count
- `created_at` - Account creation timestamp
- `last_login` - Last login timestamp

### Enhanced Pixel Table
- `x, y` - Pixel coordinates (primary key)
- `r, g, b` - RGB color values
- `ip_address` - IP address (for anonymous users)
- `user_id` - User ID (for authenticated users)
- `last_updated` - Timestamp
- `tile_x, tile_y` - Computed tile coordinates

### Statistics Tables
- `user_stats` - Per-user/IP pixel placement statistics
- `active_users` - Recent activity tracking
- `email_verifications` - Email verification tokens

## 🔌 Frontend Integration Guide

### 1. Authentication Flow
```javascript
// Register user
const register = async (username, email, password, displayName) => {
  const response = await fetch('/api/auth/register', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username, email, password, display_name: displayName })
  });
  return response.json();
};

// Login user
const login = async (username, password) => {
  const response = await fetch('/api/auth/login', {
    method: 'POST', 
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username, password })
  });
  const data = await response.json();
  
  if (data.access_token) {
    localStorage.setItem('token', data.access_token);
  }
  return data;
};

// Verify email
const verifyEmail = async (token) => {
  const response = await fetch('/api/auth/verify-email', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token })
  });
  return response.json();
};
```

### 2. Authenticated Requests
```javascript
// Get JWT token from storage
const getAuthHeader = () => {
  const token = localStorage.getItem('token');
  return token ? { 'Authorization': `Bearer ${token}` } : {};
};

// Place pixel (authenticated)
const placePixel = async (x, y, r, g, b, checksum) => {
  const response = await fetch('/api/pixel', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...getAuthHeader()
    },
    body: JSON.stringify({ x, y, r, g, b, checksum })
  });
  return response.json();
};

// Get current user
const getCurrentUser = async () => {
  const response = await fetch('/api/auth/me', {
    headers: getAuthHeader()
  });
  return response.json();
};
```

### 3. Profile Management
```javascript
// Update profile
const updateProfile = async (displayName, bio) => {
  const response = await fetch('/api/user/profile', {
    method: 'PUT',
    headers: {
      'Content-Type': 'application/json',
      ...getAuthHeader()
    },
    body: JSON.stringify({ display_name: displayName, bio })
  });
  return response.json();
};

// Upload profile picture
const uploadProfilePicture = async (file) => {
  const formData = new FormData();
  formData.append('file', file);
  
  const response = await fetch('/api/user/profile-picture', {
    method: 'POST',
    headers: getAuthHeader(),
    body: formData
  });
  return response.json();
};

// Get user statistics
const getUserStats = async () => {
  const response = await fetch('/api/user/stats', {
    headers: getAuthHeader()
  });
  return response.json();
};
```

### 4. Frontend UI Components Needed

#### Registration Form
```html
<form id="register-form">
  <input type="text" name="username" placeholder="Username" required minlength="3" maxlength="50">
  <input type="email" name="email" placeholder="Email" required>
  <input type="password" name="password" placeholder="Password" required minlength="8">
  <input type="text" name="displayName" placeholder="Display Name (optional)" maxlength="100">
  <button type="submit">Register</button>
</form>
```

#### Login Form
```html
<form id="login-form">
  <input type="text" name="username" placeholder="Username" required>
  <input type="password" name="password" placeholder="Password" required>
  <button type="submit">Login</button>
</form>
```

#### Profile Settings
```html
<div id="profile-settings">
  <input type="text" id="display-name" placeholder="Display Name">
  <textarea id="bio" placeholder="Bio" maxlength="500"></textarea>
  <input type="file" id="profile-picture" accept="image/*">
  <button onclick="updateProfile()">Save Profile</button>
</div>
```

### 5. Email Verification Page
Create a page at `/verify` that reads the token from URL parameters:
```javascript
const urlParams = new URLSearchParams(window.location.search);
const token = urlParams.get('token');

if (token) {
  verifyEmail(token).then(result => {
    if (result.success) {
      // Show success message and redirect to login
    } else {
      // Show error message
    }
  });
}
```

## 🔒 Security Features

- **Password Hashing** - Bcrypt with automatic salt generation
- **JWT Tokens** - Secure, stateless authentication (30-day expiry)
- **Email Verification** - Required before account activation
- **Rate Limiting** - Per-user rate limiting for authenticated users
- **Input Validation** - Comprehensive validation for all user inputs
- **File Upload Security** - File type validation and size limits
- **IP Masking** - Privacy-focused IP address handling

## 📈 Statistics & Analytics

### User Statistics Include:
- Total pixels placed
- Pixels placed today/this week/this month
- Account age in days
- Last pixel placement timestamp
- Top 10 favorite colors with usage counts
- 30-day activity heatmap

### Canvas Statistics:
- Total pixels on canvas
- Active users (last hour)
- Rate limit status for current user

## 🚀 Performance Optimizations

- **Async Database Operations** - Non-blocking database queries
- **Connection Pooling** - Efficient database connection management
- **Tile-based Updates** - Only transmit changed 128x128 tile sections
- **Checksum Verification** - Avoid unnecessary data transfer
- **Image Processing** - Automatic profile picture optimization
- **Database Indexing** - Optimized queries for user and pixel lookups

## 🔄 Migration from PHP Backend

The new backend maintains compatibility with existing frontend code:

- `get_state.php` → `GET/POST /api/state`
- `set_pixel.php` → `POST /api/pixel`
- `get_stats.php` → `GET /api/stats`
- `get_raw_canvas.php` → `GET /api/canvas`
- `scramble.php` → `POST /api/admin/scramble`
- `monitor.php` → `GET /api/monitor`
- `get_ip.php` → `GET /api/ip`

### Breaking Changes:
1. **Authentication Required** - Some endpoints now support/require JWT tokens
2. **Enhanced Statistics** - Stats response format includes new fields
3. **User-based Rate Limiting** - Authenticated users get different rate limiting

## 🛠️ Development

The backend is structured as:
- `main.py` - FastAPI application entry point
- `api.py` - API route definitions with full auth integration
- `database.py` - SQLAlchemy models with user tables
- `models.py` - Pydantic request/response models
- `services.py` - Business logic and database operations
- `config.py` - Configuration management with email settings
- `email_service.py` - Beautiful HTML email templates and SMTP handling

## 🎯 Future Enhancements

- WebSocket support for real-time collaborative features
- Redis caching for improved performance
- Advanced admin tools and moderation features
- User roles and permissions system
- Canvas history and rollback features
- Advanced statistics and leaderboards
- Social features (following users, collaborative projects)
- API rate limiting with Redis
- OAuth integration (Google, Discord, GitHub)
- Two-factor authentication

## 📞 Support

For questions about the authentication system:
1. Check the `.env.example` file for configuration
2. Verify email SMTP settings are correct
3. Ensure JWT secret key is properly set
4. Check database migrations completed successfully

The system is designed to be production-ready with proper error handling, validation, and security measures. 