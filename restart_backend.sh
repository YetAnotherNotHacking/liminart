#!/bin/bash

echo "Restarting Python backend with database fixes..."

# Stop the current container
echo "Stopping current container..."
sudo docker stop $(sudo docker ps -q --filter ancestor=pixel-canvas-backend) 2>/dev/null

# Run database migration
echo "Running database migration..."
cd backend
python3 migrate_database.py

# Rebuild the container with the latest code
echo "Rebuilding container..."
sudo docker build --no-cache -t pixel-canvas-backend .

# Run the container
echo "Starting updated container..."
sudo docker run -d -p 6969:9696 \
  -e DATABASE_URL="postgresql://pixel_user:secure_password_2024@host.docker.internal:5432/pixel_canvas" \
  -e SECRET_KEY="your-super-secret-key-change-this-in-production-2024" \
  -e SMTP_SERVER="smtp.gmail.com" \
  -e SMTP_PORT="587" \
  -e SMTP_USERNAME="your-email@gmail.com" \
  -e SMTP_PASSWORD="your-app-password" \
  -e FRONTEND_URL="https://silverflag.net" \
  pixel-canvas-backend

echo "Backend restarted! Check logs with: sudo docker logs \$(sudo docker ps -q --filter ancestor=pixel-canvas-backend)"
echo "Test the API with: curl https://silverflag.net:6969/api/health" 