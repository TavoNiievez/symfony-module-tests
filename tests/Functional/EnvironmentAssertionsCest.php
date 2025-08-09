<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FunctionalTester;

class EnvironmentAssertionsCest
{
    public function _before(): void
    {
        @mkdir(codecept_root_dir() . 'var/log', 0777, true);
        @mkdir(codecept_root_dir() . 'var/sessions/test', 0777, true);
    }

    public function symfonyKernelAssertions(FunctionalTester $I): void
    {
        $I->assertKernelEnvironment('test');
        $I->assertDebugModeIsEnabled();
        $I->assertSymfonyVersion('>=', '7.3');
        $I->assertAppEnvAndDebugMatchKernel();
        $I->assertAppCacheIsWritable();
        $I->assertProjectStructureIsSane();
    }

    public function serviceAndBundleAssertions(FunctionalTester $I): void
    {
        $I->assertBundleIsEnabled(\Symfony\Bundle\FrameworkBundle\FrameworkBundle::class);
    }

    public function securityAssertions(FunctionalTester $I): void
    {
        $I->assertFirewallIsActive('main');
    }

    public function doctrineAssertions(FunctionalTester $I): void
    {
        $I->assertDoctrineDatabaseIsUp();
    }

    public function otherComponentAssertions(FunctionalTester $I): void
    {
        $I->assertSessionSavePathIsWritable();
        $I->assertKernelCharsetIs('UTF-8');
    }
}
