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
    await asyncio.sleep(5)  # Wait for PostgreSQL to start
    await init_db()
    yield
    # Shutdown
    pass

app = FastAPI(
    title="Pixel Canvas API",
    description="Backend API for collaborative pixel canvas",
    version="1.0.0",
    lifespan=lifespan
)

# CORS middleware
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],  # Configure appropriately for production
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Include API routes
app.include_router(router, prefix="/api")

@app.get("/")
async def root():
    return {"message": "Pixel Canvas API", "version": "1.0.0"}

@app.get("/health")
async def health_check():
    return {"status": "healthy"}

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=9696) 