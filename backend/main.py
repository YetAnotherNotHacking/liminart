from fastapi import FastAPI, HTTPException, Depends, Request
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from contextlib import asynccontextmanager
import asyncio
from database import init_db
from api import router
from config import settings

@asynccontextmanager
async def lifespan(app: FastAPI):
    # Startup
    print("Starting Pixel Canvas API...")
    await asyncio.sleep(5)  # Wait for PostgreSQL to start
    await init_db()
    print("Database tables created successfully")
    yield
    # Shutdown
    print("Shutting down Pixel Canvas API...")

app = FastAPI(
    title="Pixel Canvas API",
    description="Backend API for collaborative pixel canvas",
    version="1.0.0",
    lifespan=lifespan
)

# CORS middleware - Allow all origins for development
app.add_middleware(
    CORSMiddleware,
    allow_origins=[
        "http://localhost:3000",
        "http://localhost:8080", 
        "https://silverflag.net",
        "http://silverflag.net",
        "*"  # Allow all origins for development
    ],
    allow_credentials=True,
    allow_methods=["GET", "POST", "PUT", "DELETE", "OPTIONS"],
    allow_headers=[
        "Content-Type",
        "Authorization", 
        "X-Requested-With",
        "Accept",
        "Origin",
        "User-Agent",
        "DNT",
        "Cache-Control",
        "X-Mx-ReqToken",
        "Keep-Alive",
        "X-Requested-With",
        "If-Modified-Since"
    ],
)

# Add middleware to log requests for debugging
@app.middleware("http")
async def log_requests(request: Request, call_next):
    print(f"Incoming request: {request.method} {request.url}")
    print(f"Headers: {dict(request.headers)}")
    
    try:
        response = await call_next(request)
        print(f"Response status: {response.status_code}")
        return response
    except Exception as e:
        print(f"Request failed: {e}")
        return JSONResponse(
            status_code=500,
            content={"detail": f"Internal server error: {str(e)}"}
        )

# Include API routes
app.include_router(router, prefix="/api")

@app.get("/")
async def root():
    return {"message": "Pixel Canvas API", "version": "1.0.0", "status": "running"}

@app.get("/health")
async def health_check():
    return {"status": "healthy", "timestamp": asyncio.get_event_loop().time()}

# Add CORS preflight handler
@app.options("/{full_path:path}")
async def preflight_handler(request: Request):
    return JSONResponse(
        content="OK",
        headers={
            "Access-Control-Allow-Origin": "*",
            "Access-Control-Allow-Methods": "GET, POST, PUT, DELETE, OPTIONS",
            "Access-Control-Allow-Headers": "*",
        }
    )

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=9696) 