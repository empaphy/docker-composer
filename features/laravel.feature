Feature: Laravel package integration

  Scenario: Laravel autodiscovery and console redirection
    Given a Laravel project
    When I run "DOCKER_COMPOSER_LARAVEL=0 composer install" in the project
    Then the Laravel package should autodiscover the Docker-Composer service provider
    When I run "DOCKER_COMPOSER_LARAVEL=0 php artisan vendor:publish --tag=docker-composer-config --force" in the project
    Then the Laravel Docker-Composer configuration should exist
    When I configure Laravel Docker-Composer redirection
    And I run "docker compose down --volumes --remove-orphans" in the project
    And I run "DOCKER_COMPOSER_LARAVEL=true php artisan mark" in the project
    Then the last command error output should contain "Running artisan mark in Docker Compose service php."
    Then the project file "result.txt" should contain "1"
    When I run "DOCKER_COMPOSER_LARAVEL=true php artisan class-map" in the project
    Then the project file "class.txt" should contain "mapped"
    When I run "DOCKER_COMPOSER_LARAVEL=true php scripts/bootstrap.php" in the project
    Then the project file "script.txt" should contain "mapped"
    When I run "DOCKER_COMPOSER_LARAVEL=true php artisan host-only" in the project
    Then the project file "host.txt" should contain "host"
    When I delete the project file "result.txt"
    And I run "DOCKER_COMPOSER_LARAVEL=0 php artisan mark" in the project
    Then the project file "result.txt" should contain "host"
