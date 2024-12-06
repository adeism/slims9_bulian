{ config, pkgs, ... }:

let
  # Define the persistent volumes
  mariadbData = pkgs.dockerTools.volume "mariadb_data";
  apacheData = pkgs.dockerTools.volume "apache_data";
  phpmyadminData = pkgs.dockerTools.volume "phpmyadmin_data";

in {

  # Define services
  services.docker.containers = [
    # MariaDB Container
    {
      name = "mariadb";
      image = "mariadb:latest";
      volumes = [
        mariadbData
      ];
      environment = {
        MYSQL_ROOT_PASSWORD = "yourpassword";
      };
    }

    # Apache + PHP Container
    {
      name = "php-apache";
      image = "php:8.3.13-apache";
      volumes = [
        apacheData
        /path/to/your/web/folder:/var/www/html
      ];
      environment = {
        APACHE_ENABLE_MPM = "prefork";
        PHP_ENABLE_MODULES = "mysqli,pdo_mysql,gd,gettext,mbstring";
      };
    }

    # phpMyAdmin Container
    {
      name = "phpmyadmin";
      image = "phpmyadmin/phpmyadmin";
      volumes = [
        phpmyadminData
      ];
      environment = {
        PMA_HOST = "mariadb";
        MYSQL_ROOT_PASSWORD = "yourpassword";
      };
    }
  ];

  # Expose the necessary ports
  services.docker.exposePorts = [
    { containerName = "php-apache"; port = 80; }
    { containerName = "phpmyadmin"; port = 8080; }
  ];
}
