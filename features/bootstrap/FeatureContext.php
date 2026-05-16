<?php

/**
 * @noinspection PhpIllegalPsrClassPathInspection
 */

declare(strict_types=1);

use Behat\Behat\Context\Context;
use Behat\Step\Given;

/**
 * Defines executable Composer plugin feature steps for Docker-Composer.
 */
class FeatureContext implements Context
{
    use InteractsWithTemporaryProjects;

    #[Given('a Composer project')]
    public function createComposerProject(): void
    {
        $this->projectDirectory = $this->createProject([]);
    }

    #[Given('a Composer project configured for exec mode')]
    public function createComposerProjectConfiguredForExecMode(): void
    {
        $this->projectDirectory = $this->createProject([
            'service' => 'php',
            'mode' => 'exec',
            'compose-files' => 'docker-compose.yaml',
        ]);
    }

    #[Given('a Composer project configured for exec install redirection')]
    public function createComposerProjectConfiguredForExecInstallRedirection(): void
    {
        $this->projectDirectory = $this->createProject([
            'service' => 'php',
            'mode' => 'exec',
            'compose-files' => 'docker-compose.yaml',
            'workdir' => '/usr/src/app',
        ]);
    }

    #[Given('a Composer project configured with service mapping override')]
    public function createComposerProjectConfiguredWithServiceMappingOverride(): void
    {
        $this->projectDirectory = $this->createProject([
            'service' => 'php',
            'service-mapping' => [
                'php_tools' => 'mark',
            ],
            'compose-files' => 'docker-compose.yaml',
            'workdir' => '/usr/src/app',
        ]);
    }

    #[Given('a Composer project configured for run mode')]
    public function createComposerProjectConfiguredForRunMode(): void
    {
        $this->projectDirectory = $this->createProject([
            'service' => 'php',
            'mode' => 'run',
            'compose-files' => 'docker-compose.yaml',
            'workdir' => '/usr/src/app',
        ]);
    }

    #[Given('a Composer project without Docker-Composer configuration')]
    public function createComposerProjectWithoutDockerComposerConfiguration(): void
    {
        $this->projectDirectory = $this->createProject([]);
    }

    /**
     * @param  array<string, mixed>             $dockerComposerConfig
     * @param  list<array<string, mixed>>|null  $repositories
     */
    private function createProject(array $dockerComposerConfig, ?array $repositories = null, string $requireVersion = '*'): string
    {
        $projectDirectory = $this->createTemporaryProjectDirectory('docker-composer-integration-');

        $composerJson = [
            'name' => 'empaphy/docker-composer-integration',
            'description' => 'Temporary docker-composer integration fixture.',
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
            'repositories' => $repositories ?? [[
                'type' => 'path',
                'url' => dirname(__DIR__, 2),
                'options' => ['symlink' => false],
            ]],
            'require' => [
                'empaphy/docker-composer' => $requireVersion,
            ],
            'config' => [
                'allow-plugins' => [
                    'empaphy/docker-composer' => true,
                ],
            ],
            'scripts' => [
                'mark' => '@php -r "file_put_contents(\'result.txt\', getenv(\'DOCKER_COMPOSER_TEST_MARK\') ?: (getenv(\'DOCKER_COMPOSER_INSIDE\') ?: \'host\'));"',
                'post-autoload-dump' => '@php -r "file_put_contents(\'lifecycle.txt\', getenv(\'DOCKER_COMPOSER_INSIDE\') ?: \'host\');"',
            ],
            'extra' => [
                'docker-composer' => $dockerComposerConfig,
            ],
        ];

        $this->writeJson($projectDirectory . '/composer.json', $composerJson);
        file_put_contents($projectDirectory . '/docker-compose.yaml', sprintf(<<<'YAML'
services:
  php:
    image: %s
    command: ['sleep', 'infinity']
    working_dir: /usr/src/app
    volumes:
      - { type: bind, source: '.', target: '/usr/src/app' }
  php_tools:
    image: %s
    command: ['sleep', 'infinity']
    environment:
      DOCKER_COMPOSER_TEST_MARK: override
    working_dir: /usr/src/app
    volumes:
      - { type: bind, source: '.', target: '/usr/src/app' }
YAML, $this->getComposerImage(), $this->getComposerImage()));

        return $projectDirectory;
    }
}
