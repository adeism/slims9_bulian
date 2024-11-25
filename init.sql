-- Set root password
ALTER USER 'root'@'localhost' IDENTIFIED BY 'K3tikaja!';
FLUSH PRIVILEGES;

-- Create slims database
CREATE DATABASE slims CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create slims user and grant privileges
CREATE USER 'slims'@'localhost' IDENTIFIED BY 'Janganlupaberdoa!';
GRANT ALL PRIVILEGES ON slims.* TO 'slims'@'localhost';
FLUSH PRIVILEGES;
