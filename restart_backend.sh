#!/bin/bash

echo "=== Pixel Canvas Backend Restart ==="
echo "This script will restart the backend with database migration"
echo

# Change to backend directory
cd backend

# Stop any running containers
echo "Stopping existing containers..."
sudo docker-compose down

# Run database migration
echo "Running database migration to fix INET column issues..."
python3 migrate_database.py

# Wait a moment for cleanup
sleep 2

# Start the backend
echo "Starting backend services..."
sudo docker-compose up -d

# Wait for services to start
echo "Waiting for services to start..."
sleep 10

# Check if services are running
echo "Checking service status..."
sudo docker-compose ps

# Check logs
echo "Recent logs:"
sudo docker-compose logs --tail=20

echo
echo "=== Backend Restart Complete ==="
echo "Backend should be running at: http://localhost:9696"
echo "API documentation: http://localhost:9696/docs"
echo "Health check: http://localhost:9696/health"
echo
echo "To view logs: sudo docker-compose logs -f"
echo "To stop: sudo docker-compose down"