<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types = 1);

namespace Magento\FunctionalTestingFramework\Console;

use Magento\FunctionalTestingFramework\Config\MftfApplicationConfig;
use Magento\FunctionalTestingFramework\Util\TestGenerator;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Magento\FunctionalTestingFramework\Exceptions\TestFrameworkException;

class RunTestCommand extends BaseGenerateCommand
{
    /**
     * Configures the current command.
     *
     * @return void
     */
    protected function configure()
    {
        $this->setName("run:test")
            ->setDescription("generation and execution of test(s) defined in xml")
            ->addArgument(
                'name',
                InputArgument::REQUIRED | InputArgument::IS_ARRAY,
                "name of tests to generate and execute"
            )->addOption('skip-generate', 'k', InputOption::VALUE_NONE, "skip generation and execute existing test");

        parent::configure();
    }

    /**
     * Executes the current command.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return integer
     * @throws \Exception
     *
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tests = $input->getArgument('name');
        $skipGeneration = $input->getOption('skip-generate');
        $force = $input->getOption('force');
        $remove = $input->getOption('remove');
        $debug = $input->getOption('debug') ?? MftfApplicationConfig::LEVEL_DEVELOPER; // for backward compatibility
        $allowSkipped = $input->getOption('allowSkipped');

        if ($skipGeneration and $remove) {
            // "skip-generate" and "remove" options cannot be used at the same time
            throw new TestFrameworkException(
                "\"skip-generate\" and \"remove\" options can not be used at the same time."
            );
        }

        $testConfiguration = $this->getTestAndSuiteConfiguration($tests);

        if (!$skipGeneration) {
            $command = $this->getApplication()->find('generate:tests');
            $args = [
                '--tests' => $testConfiguration,
                '--force' => $force,
                '--remove' => $remove,
                '--debug' => $debug,
                '--allowSkipped' => $allowSkipped
            ];
            $command->run(new ArrayInput($args), $output);
        }
        // tests with resolved suite references
        $resolvedTests = $this->resolveSuiteReferences($testConfiguration);

        $codeceptionCommand = realpath(PROJECT_ROOT . '/vendor/bin/codecept') . ' run functional ';
        $testsDirectory = TESTS_MODULE_PATH . DIRECTORY_SEPARATOR . TestGenerator::GENERATED_DIR . DIRECTORY_SEPARATOR;
        $returnCode = 0;
        //execute only tests specified as arguments in run command
        foreach ($resolvedTests as $test) {
            //set directory as suite name for tests in suite, if not set to "default"
            if (strpos($test, ':')) {
                list($testGroup, $testName) = explode(":", $test);
            } else {
                list($testGroup, $testName) = [TestGenerator::DEFAULT_DIR, $test];
            }
            $testGroup = $testGroup . DIRECTORY_SEPARATOR;
            $testName = $testName . 'Cest.php';
            if (!realpath($testsDirectory . $testGroup . $testName)) {
                throw new TestFrameworkException(
                    $testName . " is not available under " . $testsDirectory . $testGroup
                );
            }
            $fullCommand = $codeceptionCommand . $testsDirectory . $testGroup . $testName . ' --verbose --steps';
            $process = new Process($fullCommand);
            $process->setWorkingDirectory(TESTS_BP);
            $process->setIdleTimeout(600);
            $process->setTimeout(0);

            $returnCode = max($returnCode, $process->run(
                function ($type, $buffer) use ($output) {
                    $output->write($buffer);
                }
            ));
        }
        return $returnCode;
    }

    /**
     * Get an array of tests with resolved suite references from $testConfiguration
     * eg: if test is referenced in a suite, it'll be stored in format suite:test
     * @param string $testConfigurationJson
     * @return array
     */
    private function resolveSuiteReferences($testConfigurationJson)
    {
        $testConfiguration = json_decode($testConfigurationJson, true);
        $testsArray = $testConfiguration['tests'] ?? [];
        $suitesArray = $testConfiguration['suites'] ?? [];
        $testArrayBuilder = [];

        foreach ($suitesArray as $suite => $tests) {
            foreach ($tests as $test) {
                $testArrayBuilder[] = "$suite:$test";
            }
        }
        return array_merge($testArrayBuilder, $testsArray);
    }
}
