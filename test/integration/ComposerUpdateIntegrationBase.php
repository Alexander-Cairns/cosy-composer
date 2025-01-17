<?php

namespace eiriksm\CosyComposerTest\integration;

use PHPUnit\Framework\MockObject\MockObject;
use Violinist\Slug\Slug;

abstract class ComposerUpdateIntegrationBase extends Base
{

    protected $packageForUpdateOutput;

    protected $packageVersionForFromUpdateOutput;

    protected $packageVersionForToUpdateOutput;

    protected $composerAssetFiles;

    protected $fakePrUrl = 'http://example.com/pr';

    protected $checkPrUrl = false;

    protected $prParams = [];

    /**
     * @var MockObject
     */
    protected $mockProvider;

    public function setUp()
    {
        parent::setUp();
        if ($this->packageForUpdateOutput) {
            $this->getMockOutputWithUpdate($this->packageForUpdateOutput, $this->packageVersionForFromUpdateOutput, $this->packageVersionForToUpdateOutput);
        }
        if ($this->composerAssetFiles) {
            $this->createComposerFileFromFixtures($this->dir, sprintf('%s.json', $this->composerAssetFiles));
        }
        // Then we are going to mock the provider factory.
        $mock_provider = $this->getMockProvider();
        $mock_executer = $this->getMockExecuterWithReturnCallback(
            function ($cmd) {
                $return = 0;
                $expected_command = $this->createExpectedCommandForPackage($this->packageForUpdateOutput);
                if ($cmd == $expected_command) {
                    $this->placeUpdatedComposerLock();
                }
                $this->handleExecutorReturnCallback($cmd, $return);
                return $return;
            }
        );
        $this->cosy->setExecuter($mock_executer);
        $this->setDummyGithubProvider();
        $this->placeInitialComposerLock();
        $this->mockProvider = $mock_provider;
        if ($this->checkPrUrl) {
            $this->mockProvider->method('createPullRequest')
                ->willReturnCallback(function (Slug $slug, array $params) {
                    $this->prParams = $params;
                    return [
                        'html_url' => $this->fakePrUrl,
                    ];
                });
        }
    }

    protected function placeInitialComposerLock()
    {
        $this->placeComposerLockContentsFromFixture(sprintf('%s.lock', $this->composerAssetFiles), $this->dir);
    }

    protected function placeUpdatedComposerLock()
    {
        $this->placeComposerLockContentsFromFixture(sprintf('%s.lock.updated', $this->composerAssetFiles), $this->dir);
    }

    protected function handleExecutorReturnCallback($cmd, &$return)
    {
    }

    public function runtestExpectedOutput()
    {
        $this->cosy->run();
        if ($this->checkPrUrl) {
            $this->assertOutputContainsMessage($this->fakePrUrl, $this->cosy);
        }
    }
}
