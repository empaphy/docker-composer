Feature: Composer plugin command redirection

  Scenario: Exec mode redirects custom and lifecycle Composer scripts with auto-up
    Given a Composer project configured for exec mode
    When I run "composer install" in the project
    And I run "docker compose down --volumes --remove-orphans" in the project
    And I run "composer run-script mark" in the project
    Then the project file "result.txt" should contain "1"
    When I delete the project file "lifecycle.txt"
    And I run "composer dump-autoload" in the project
    Then the project file "lifecycle.txt" should contain "1"

  Scenario: Install redirects after the plugin is already installed
    Given a Composer project configured for exec install redirection
    When I run "composer install" in the project
    And I run "composer install" in the project
    Then the last command error output should contain "Running composer install in Docker Compose service php."

  Scenario: Service mapping override redirects to the configured service
    Given a Composer project configured with service mapping override
    When I run "composer install" in the project
    And I run "composer run-script mark" in the project
    Then the project file "result.txt" should contain "override"

  Scenario: Run mode, disabled mode, inside-container behavior, and missing config behavior
    Given a Composer project configured for run mode
    When I run "composer install" in the project
    And I run "composer run-script mark" in the project
    Then the project file "result.txt" should contain "1"
    When I delete the project file "result.txt"
    And I run "DOCKER_COMPOSER_DISABLE=1 composer run-script mark" in the project
    Then the project file "result.txt" should contain "host"
    When I delete the project file "result.txt"
    And I run "composer run-script mark" in the "php" service of the Composer project
    Then the project file "result.txt" should contain "1"
    Given a Composer project without Docker-Composer configuration
    When I run "composer install" in the project
    And I run "composer run-script mark" in the project
    Then the project file "result.txt" should contain "host"
