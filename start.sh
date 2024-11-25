#!/bin/sh

# Start MySQL in the background and wait for it to be ready
nohup /usr/bin/mysqld --user=mysql --datadir=/var/lib/mysql > /var/log/mysql.log 2>&1 &

# Wait for MySQL to be ready
timeout=30
while ! mysqladmin ping -h localhost --silent; do
    timeout=$((timeout - 1))
    if [ $timeout -eq 0 ]; then
        echo "Timeout waiting for MySQL to start"
        exit 1
    fi
    echo "Waiting for MySQL to be ready..."
    sleep 1
done

echo "MySQL is ready!"

# Initialize MySQL with our custom configuration if not already initialized
if [ ! -f "/var/lib/mysql/init_done" ]; then
    echo "Initializing MySQL..."
    mysql < /docker-entrypoint-initdb.d/init.sql
    touch /var/lib/mysql/init_done
    echo "MySQL initialization completed!"
fi

# Start supervisord
echo "Starting supervisord..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
