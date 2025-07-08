#!/bin/bash

# Start PostgreSQL
service postgresql start

# Wait for PostgreSQL to be ready
while ! pg_isready -U postgres; do
    echo "Waiting for PostgreSQL to be ready..."
    sleep 2
done

# Create database and user if they don't exist
sudo -u postgres psql -c "CREATE USER pixelcanvas WITH PASSWORD 'pixelcanvas';" 2>/dev/null || true
sudo -u postgres psql -c "CREATE DATABASE pixelcanvas OWNER pixelcanvas;" 2>/dev/null || true
sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE pixelcanvas TO pixelcanvas;" 2>/dev/null || true

# Start the FastAPI application
cd /app
python main.py 