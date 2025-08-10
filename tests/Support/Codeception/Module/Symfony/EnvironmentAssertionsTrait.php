<?php

declare(strict_types=1);

namespace Codeception\Module\Symfony;

use Codeception\Module\Symfony as SymfonyModule;
use Doctrine\ORM\Tools\SchemaValidator;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use PHPUnit\Framework\Assert;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\HttpKernel\Kernel;
use Throwable;

use function array_keys;
use function date_default_timezone_get;
use function file_exists;
use function file_get_contents;
use function get_class;
use function getenv;
use function implode;
use function in_array;
use function ini_get;
use function is_array;
use function is_dir;
use function is_executable;
use function is_file;
use function is_readable;
use function is_string;
use function is_writable;
use function method_exists;
use function preg_match_all;
use function sprintf;
use function strtolower;
use function version_compare;

/**
 * Provides comprehensive environment-related assertions for Symfony applications in Codeception.
 *
 * This trait validates the project's sanity, from PHP/Composer to high-level Symfony components.
 * It aims to catch common configuration and environment issues early, especially in CI/CD pipelines.
 *
 * Recommended Usage: Create a dedicated test suite (e.g., 'environment') and a Cest file (e.g., EnvironmentCest)
 * to run these checks once per deployment or test run, as some assertions can be time-consuming.
 */
trait EnvironmentAssertionsTrait
{
    // =========================================================================
    // Symfony Application Health Assertions
    // =========================================================================

    /**
     * Asserts that the Kernel is running in the expected environment (e.g., 'test', 'dev').
     */
    public function assertKernelEnvironment(string $expectedEnv): void
    {
        $currentEnv = $this->getKernel()->getEnvironment();
        Assert::assertSame(
            $expectedEnv,
            $currentEnv,
            sprintf('Kernel is running in environment "%s" but expected "%s".', $currentEnv, $expectedEnv)
        );
    }

    /**
     * Asserts that the application's debug mode is enabled.
     */
    public function assertDebugModeIsEnabled(): void
    {
        $isDebug = $this->getKernel()->isDebug();
        Assert::assertTrue($isDebug, 'Debug mode is expected to be enabled, but it is not.');
    }

    /**
     * Asserts that the application's debug mode is disabled (production-like).
     */
    public function assertDebugModeIsDisabled(): void
    {
        $isDebug = $this->getKernel()->isDebug();
        Assert::assertFalse($isDebug, 'Debug mode is expected to be disabled, but it is enabled.');
    }

    /**
     * Asserts that the current Symfony version satisfies the given comparison.
     * Example: `$I->assertSymfonyVersion('>=', '6.4');`
     */
    public function assertSymfonyVersion(string $operator, string $version, string $message = ''): void
    {
        Assert::assertTrue(
            version_compare(Kernel::VERSION, $version, $operator),
            $message ?: sprintf('Symfony version %s does not satisfy the constraint: %s %s', Kernel::VERSION, $operator, $version)
        );
    }

    /**
     * Asserts that `APP_ENV` and `APP_DEBUG` env vars match the Kernel state.
     */
    public function assertAppEnvAndDebugMatchKernel(): void
    {
        $kernel = $this->getKernel();
        $appEnv = getenv('APP_ENV');
        $appDebug = getenv('APP_DEBUG');

        if ($appEnv !== false) {
            Assert::assertSame(
                $kernel->getEnvironment(),
                (string) $appEnv,
                sprintf('APP_ENV (%s) differs from Kernel environment (%s).', $appEnv, $kernel->getEnvironment())
            );
        }

        if ($appDebug !== false) {
            $expected = $kernel->isDebug();
            $normalized = in_array(strtolower((string) $appDebug), ['1', 'true', 'yes', 'on'], true);
            Assert::assertSame(
                $expected,
                $normalized,
                sprintf('APP_DEBUG (%s) differs from Kernel debug (%s).', $appDebug, $expected ? 'true' : 'false')
            );
        }
    }

    /**
     * Asserts that the application's cache directory is writable.
     */
    public function assertAppCacheIsWritable(): void
    {
        $cacheDir = $this->getKernel()->getCacheDir();
        Assert::assertTrue(
            is_writable($cacheDir),
            sprintf('Symfony cache directory is not writable: %s', $cacheDir)
        );
    }

    /**
     * Asserts that the application's log directory is writable (parameter-first).
     */
    public function assertAppLogIsWritable(): void
    {
        $container = $this->getSymfonyModule()->_getContainer();
        $logDir = null;
        if ($container->hasParameter('kernel.logs_dir')) {
            $logDir = (string) $container->getParameter('kernel.logs_dir');
        }

        if (!$logDir) {
            $logDir = method_exists($this->getKernel(), 'getLogDir')
                ? $this->getKernel()->getLogDir()
                : $this->getProjectDir() . 'var/log';
        }

        Assert::assertTrue(
            is_writable($logDir),
            sprintf('Symfony log directory is not writable: %s', $logDir)
        );
    }

    /**
     * Asserts that the minimal Symfony project structure exists and is usable.
     */
    public function assertProjectStructureIsSane(): void
    {
        $root = $this->getProjectDir();
        foreach (['config', 'src', 'public', 'var'] as $dir) {
            Assert::assertTrue(is_dir($root . $dir), sprintf('Directory "%s" is missing.', $dir));
        }

        foreach (['var/cache', 'var/log'] as $dir) {
            Assert::assertTrue(is_dir($root . $dir), sprintf('Directory "%s" is missing.', $dir));
            Assert::assertTrue(is_writable($root . $dir), sprintf('Directory "%s" is not writable.', $dir));
        }

        Assert::assertFileExists($root . 'config/bundles.php', 'Missing config/bundles.php file.');

        $bin = $root . 'bin/console';
        Assert::assertTrue(is_file($bin), 'bin/console is missing.');
        if (strncasecmp(PHP_OS, 'WIN', 3) !== 0) {
            Assert::assertTrue(is_executable($bin) || is_file($bin), 'bin/console is not executable.');
        }
    }

    /**
     * Asserts that all keys in example env file(s) exist either in the provided env file(s) OR as OS env vars.
     * This validates presence only, not values. It also considers common local/test files if present.
     *
     * @param non-empty-string $envPath
     * @param non-empty-string $examplePath
     * @param list<non-empty-string> $additionalEnvPaths
     */
    public function assertEnvFileIsSynchronized(string $envPath = '.env', string $examplePath = '.env.example', array $additionalEnvPaths = []): void
    {
        $projectDir = $this->getProjectDir();

        $candidateExtras = ['.env.local', '.env.test', '.env.test.local'];
        foreach ($candidateExtras as $extra) {
            if (file_exists($projectDir . $extra)) {
                $additionalEnvPaths[] = $extra;
            }
        }

        $exampleContent = @file_get_contents($projectDir . $examplePath) ?: '';
        $envContent     = @file_get_contents($projectDir . $envPath) ?: '';

        foreach ($additionalEnvPaths as $extra) {
            $envContent .= "\n" . (@file_get_contents($projectDir . $extra) ?: '');
        }

        $exampleKeys = $this->extractEnvKeys($exampleContent);
        $envKeys     = $this->extractEnvKeys($envContent);

        $osKeys = array_keys($_ENV + $_SERVER);
        $present = array_flip(array_merge($envKeys, $osKeys));

        $missing = [];
        foreach ($exampleKeys as $key) {
            if (!isset($present[$key])) {
                $missing[] = $key;
            }
        }

        Assert::assertEmpty(
            $missing,
            sprintf('Missing variables from %s (not found across %s nor as OS envs): %s', $examplePath, implode(', ', array_merge([$envPath], $additionalEnvPaths)), implode(', ', $missing))
        );
    }

    // =========================================================================
    // Symfony Components & Services Assertions
    // =========================================================================

    /**
     * Asserts that a specific bundle is enabled in the Kernel.
     * @param class-string $bundleClass The Fully Qualified Class Name of the bundle.
     */
    public function assertBundleIsEnabled(string $bundleClass): void
    {
        $bundles = $this->getKernel()->getBundles();
        $found = false;
        foreach ($bundles as $bundle) {
            if ($bundle instanceof $bundleClass || get_class($bundle) === $bundleClass) {
                $found = true;
                break;
            }
        }

        Assert::assertTrue(
            $found,
            sprintf('Bundle "%s" is not enabled in the Kernel. Check config/bundles.php.', $bundleClass)
        );
    }

    // =========================================================================
    // Security Assertions
    // =========================================================================

    /**
     * Asserts that a security firewall is active (configured).
     */
    public function assertFirewallIsActive(string $firewallName): void
    {
        $container = $this->getSymfonyModule()->_getContainer();

        if ($container->hasParameter('security.firewalls')) {
            /** @var list<string> $firewalls */
            $firewalls = $container->getParameter('security.firewalls');
            Assert::assertContains($firewallName, $firewalls, sprintf('Firewall "%s" is not configured. Check your security.yaml.', $firewallName));
            return;
        }

        $contextId = 'security.firewall.map.context.' . $firewallName;
        Assert::assertTrue(
            $container->has($contextId),
            sprintf('Firewall "%s" context was not found (checked "%s").', $firewallName, $contextId)
        );
    }

    /**
     * Asserts that a role is present either as a key of the role hierarchy or among any inherited roles.
     * Skips when role hierarchy is not configured.
     */
    public function assertRoleInHierarchy(string $role): void
    {
        $container = $this->getSymfonyModule()->_getContainer();
        if (!$container->hasParameter('security.role_hierarchy.roles')) {
            Assert::markTestSkipped('Role hierarchy is not configured; skipping role hierarchy assertion.');
        }

        /** @var array<string, list<string>> $hierarchy */
        $hierarchy = $container->getParameter('security.role_hierarchy.roles');

        $all = array_keys($hierarchy);
        foreach ($hierarchy as $children) {
            foreach ($children as $child) {
                $all[] = $child;
            }
        }
        $all = array_values(array_unique($all));
        Assert::assertContains(
            $role,
            $all,
            sprintf('Role "%s" was not found in the role hierarchy. Check security.yaml.', $role)
        );
    }

    /**
     * Asserts that a secret from the Symfony vault can be resolved.
     * @param non-empty-string $secretName The name of the secret (e.g., 'DATABASE_PASSWORD').
     */
    public function assertCanResolveSecret(string $secretName): void
    {
        try {
            /** @var ContainerBagInterface $params */
            $params = $this->grabService('parameter_bag');
            $value = $params->get(sprintf('env(resolve:%s)', $secretName));

            Assert::assertIsString($value, sprintf('Secret "%s" could be resolved but did not return a string.', $secretName));
        } catch (Throwable $e) {
            Assert::fail(sprintf('Failed to resolve secret "%s". Check your vault and decryption keys. Error: %s', $secretName, $e->getMessage()));
        }
    }

    // =========================================================================
    // Doctrine Assertions
    // =========================================================================

    /**
     * Asserts that the application can connect to a Doctrine database.
     * @param non-empty-string $connectionName The name of the Doctrine connection to check.
     */
    public function assertDoctrineDatabaseIsUp(string $connectionName = 'default'): void
    {
        try {
            /** @var ManagerRegistry $doctrine */
            $doctrine = $this->grabService('doctrine');
            $connection = $doctrine->getConnection($connectionName);
            $connection->executeQuery($connection->getDatabasePlatform()->getDummySelectSQL());
            Assert::assertTrue(true, sprintf('Doctrine connection "%s" is up and responsive.', $connectionName));
        } catch (Throwable $e) {
            Assert::fail(sprintf('Doctrine connection "%s" failed: %s', $connectionName, $e->getMessage()));
        }
    }

    /**
     * Asserts that the Doctrine mapping is valid and the DB schema is in sync for one EM.
     * Programmatic equivalent of `bin/console doctrine:schema:validate`.
     * @param non-empty-string $entityManagerName
     */
    public function assertDoctrineSchemaIsValid(string $entityManagerName = 'default'): void
    {
        try {
            /** @var ManagerRegistry $doctrine */
            $doctrine = $this->grabService('doctrine');
            $em = $doctrine->getManager($entityManagerName);
            $validator = new SchemaValidator($em);
            $errors = $validator->validateMapping();
            $errorMessages = [];
            foreach ($errors as $className => $classErrors) {
                $errorMessages[] = sprintf(' - %s: %s', $className, implode('; ', $classErrors));
            }
            Assert::assertEmpty(
                $errors,
                sprintf(
                    "The Doctrine mapping is invalid for the '%s' entity manager:\n%s",
                    $entityManagerName,
                    implode("\n", $errorMessages)
                )
            );

            if (!$validator->schemaInSyncWithMetadata()) {
                Assert::fail(sprintf(
                    'The database schema is not in sync with the current mapping for the "%s" entity manager. Generate and run a new migration.',
                    $entityManagerName
                ));
            }
            Assert::assertTrue(true, sprintf('Doctrine schema for "%s" EM is valid and synchronized.', $entityManagerName));
        } catch (Throwable $e) {
            Assert::fail(sprintf('Could not validate Doctrine schema for the "%s" entity manager: %s', $entityManagerName, $e->getMessage()));
        }
    }

    /**
     * Asserts that Doctrine proxy directory is writable for a given EM.
     */
    public function assertDoctrineProxyDirIsWritable(string $entityManagerName = 'default'): void
    {
        /** @var ManagerRegistry $doctrine */
        $doctrine = $this->grabService('doctrine');
        $em = $doctrine->getManager($entityManagerName);
        $proxyDir = $em->getConfiguration()->getProxyDir();
        Assert::assertTrue($proxyDir !== null && $proxyDir !== '', sprintf('Doctrine proxy dir is not configured for EM "%s".', $entityManagerName));
        Assert::assertTrue(is_dir($proxyDir), sprintf('Doctrine proxy dir does not exist: %s', $proxyDir));
        Assert::assertTrue(is_writable($proxyDir), sprintf('Doctrine proxy dir is not writable: %s', $proxyDir));
    }

    // =========================================================================
    // Other Component Assertions
    // =========================================================================

    /**
     * Asserts that an asset manifest file exists, checking for Webpack Encore or AssetMapper.
     * A common CI/CD failure point is missing frontend assets.
     */
    public function assertAssetManifestExists(): void
    {
        $projectDir = $this->getProjectDir();
        $encoreManifest = $projectDir . 'public/build/manifest.json';
        $mapperManifest = $projectDir . 'public/assets/manifest.json';
        $encoreEntrypoints = $projectDir . 'public/build/entrypoints.json';

        if (is_readable($encoreManifest) && is_readable($encoreEntrypoints)) {
            Assert::assertJson((string) file_get_contents($encoreManifest), 'Webpack Encore manifest.json is not valid JSON.');
            Assert::assertJson((string) file_get_contents($encoreEntrypoints), 'Webpack Encore entrypoints.json is not valid JSON.');
            Assert::assertTrue(true, 'Webpack Encore manifest files found and are valid.');
            return;
        }

        if (is_readable($mapperManifest)) {
            Assert::assertJson((string) file_get_contents($mapperManifest), 'AssetMapper manifest.json is not valid JSON.');
            Assert::assertTrue(true, 'AssetMapper manifest file found and is valid.');
            return;
        }

        Assert::fail('No asset manifest file found. Checked for Webpack Encore (public/build/manifest.json) and AssetMapper (public/assets/manifest.json).');
    }

    /**
     * Asserts that the session save path is writable when using file-based sessions.
     * Skips when session storage is not file-based.
     */
    public function assertSessionSavePathIsWritable(): void
    {
        $container = $this->getSymfonyModule()->_getContainer();

        $isFileBased = false;
        if ($container->has('session.storage.factory.native_file') || $container->has('session.handler.native_file')) {
            $isFileBased = true;
        }
        $iniHandler = (string) (ini_get('session.save_handler') ?: '');
        if ($iniHandler === 'files') {
            $isFileBased = true;
        }

        if (!$isFileBased) {
            Assert::markTestSkipped('Session storage is not file-based; skipping save path writability check.');
        }

        $savePath = null;

        if ($container->hasParameter('session.storage.options')) {
            $options = $container->getParameter('session.storage.options');
            if (is_array($options) && isset($options['save_path']) && is_string($options['save_path']) && $options['save_path'] !== '') {
                $savePath = $options['save_path'];
            }
        }

        if (!$savePath) {
            $ini = (string) (ini_get('session.save_path') ?: '');
            if ($ini !== '') {
                $savePath = $ini;
            }
        }

        if (!$savePath) {
            $env = $this->getKernel()->getEnvironment();
            $savePath = $this->getProjectDir() . 'var/sessions/' . $env;
        }

        Assert::assertTrue(is_dir($savePath), sprintf('Session save path is not a directory: %s', $savePath));
        Assert::assertTrue(is_writable($savePath), sprintf('Session save path is not writable: %s', $savePath));
    }

    /**
     * Asserts the Kernel charset matches the expected value.
     */
    public function assertKernelCharsetIs(string $expected = 'UTF-8'): void
    {
        $charset = $this->getKernel()->getCharset();
        Assert::assertSame($expected, $charset, sprintf('Kernel charset is "%s" but expected "%s".', $charset, $expected));
    }

    // =========================================================================
    // Trait Internals
    // =========================================================================

    /**
     * Helper to get the Symfony module.
     */
    private function getSymfonyModule(): SymfonyModule
    {
        $symfonyModule = $this->getModule('Symfony');
        if (!$symfonyModule instanceof SymfonyModule) {
            throw new LogicException('This trait can only be used in a class that uses the Codeception Symfony module.');
        }
        return $symfonyModule;
    }

    /**
     * Helper to get a service from the container.
     */
    private function grabService(string $serviceId): object
    {
        return $this->getSymfonyModule()->_getContainer()->get($serviceId);
    }

    /**
     * Helper to get the Kernel instance.
     */
    private function getKernel(): Kernel
    {
        /** @var Kernel $kernel */
        $kernel = $this->getSymfonyModule()->grabService('kernel');
        return $kernel;
    }

    /**
     * Helper to get the project's root directory.
     */
    private function getProjectDir(): string
    {
        return $this->getKernel()->getProjectDir() . '/';
    }

    /**
     * Extracts variable keys from the content of a .env file.
     * @return list<string>
     */
    private function extractEnvKeys(string $content): array
    {
        $keys = [];
        if (preg_match_all('/^(?!#)\s*([a-zA-Z_][a-zA-Z0-9_]*)=/m', $content, $matches)) {
            $keys = $matches[1];
        }
        return $keys;
    }
}
