FROM python:3.11-slim

# Install system dependencies
RUN apt-get update && apt-get install -y \
    postgresql \
    postgresql-contrib \
    sudo \
    && rm -rf /var/lib/apt/lists/*

# Set up PostgreSQL
USER postgres
RUN /etc/init.d/postgresql start && \
    psql --command "CREATE USER pixelcanvas WITH SUPERUSER PASSWORD 'pixelcanvas';" && \
    createdb -O pixelcanvas pixelcanvas

USER root

# Set up Python environment
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy application code
COPY . .

# Make startup script executable
RUN chmod +x start.sh

# Create directories and set permissions
RUN mkdir -p /var/run/postgresql
RUN chown -R postgres:postgres /var/lib/postgresql
RUN chown -R postgres:postgres /var/run/postgresql

EXPOSE 9696

CMD ["./start.sh"] 