<?php

namespace PHPCensor\Plugin;

use PHPCensor\Builder;
use PHPCensor\Model\Build;
use PHPCensor\Plugin\Util\TestResultParsers\Codeception as Parser;
use PHPCensor\Plugin;
use Symfony\Component\Yaml\Parser as YamlParser;
use PHPCensor\ZeroConfigPluginInterface;

/**
 * Codeception Plugin - Enables full acceptance, unit, and functional testing.
 * 
 * @author Don Gilbert <don@dongilbert.net>
 * @author Igor Timoshenko <contact@igortimoshenko.com>
 * @author Adam Cooper <adam@networkpie.co.uk>
 */
class Codeception extends Plugin implements ZeroConfigPluginInterface
{
    /** @var string */
    protected $args = '';

    /**
     * @var string $ymlConfigFile The path of a yml config for Codeception
     */
    protected $ymlConfigFile;

    /**
     * @var array $path The path to the codeception tests folder.
     */
    protected $path = [
        'tests/_output',
        'tests/_log',
    ];

    /**
     * @return string
     */
    public static function pluginName()
    {
        return 'codeception';
    }

    /**
     * {@inheritdoc}
     */
    public function __construct(Builder $builder, Build $build, array $options = [])
    {
        parent::__construct($builder, $build, $options);

        if (empty($options['config'])) {
            $this->ymlConfigFile = self::findConfigFile($this->builder->buildPath);
        } else {
            $this->ymlConfigFile = $options['config'];
        }

        if (isset($options['args'])) {
            $this->args = (string) $options['args'];
        }

        if (isset($options['path'])) {
            array_unshift($this->path, $options['path']);
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function canExecuteOnStage($stage, Build $build)
    {
        return $stage == Build::STAGE_TEST && !is_null(self::findConfigFile($build->getBuildPath()));
    }

    /**
     * Try and find the codeception YML config file.
     * @param $buildPath
     * @return null|string
     */
    public static function findConfigFile($buildPath)
    {
        if (file_exists($buildPath . 'codeception.yml')) {
            return 'codeception.yml';
        }

        if (file_exists($buildPath . 'codeception.dist.yml')) {
            return 'codeception.dist.yml';
        }

        return null;
    }

    /**
     * Runs Codeception tests
     */
    public function execute()
    {
        if (empty($this->ymlConfigFile)) {
            throw new \Exception("No configuration file found");
        }

        // Run any config files first. This can be either a single value or an array.
        return $this->runConfigFile($this->ymlConfigFile);
    }

    /**
     * Run tests from a Codeception config file.
     * @param $configPath
     * @return bool|mixed
     * @throws \Exception
     */
    protected function runConfigFile($configPath)
    {
        $codeception = $this->findBinary('codecept');

        if (!$codeception) {
            $this->builder->logFailure(sprintf('Could not find "%s" binary', 'codecept'));

            return false;
        }

        $cmd = 'cd "%s" && ' . $codeception . ' run -c "%s" ' . $this->args . ' --xml';

        $configPath = $this->builder->buildPath . $configPath;
        $success = $this->builder->executeCommand($cmd, $this->builder->buildPath, $configPath);

        $parser = new YamlParser();
        $yaml   = file_get_contents($configPath);
        $config = (array)$parser->parse($yaml);

        $outputPath = null;
        if ($config && isset($config['paths']['log'])) {
            $outputPath = $this->builder->buildPath . $config['paths']['log'] . '/';
        }
        
        if (!file_exists($outputPath . 'report.xml')) {
            foreach ($this->path as $path) {
                $outputPath = $this->builder->buildPath . rtrim($path, '/\\') . '/';
                if (file_exists($outputPath . 'report.xml')) {
                    break;
                }
            }
        }

        $parser = new Parser($this->builder, ($outputPath . 'report.xml'));
        $output = $parser->parse();

        $meta = [
            'tests'     => $parser->getTotalTests(),
            'timetaken' => $parser->getTotalTimeTaken(),
            'failures'  => $parser->getTotalFailures()
        ];

        $this->build->storeMeta((self::pluginName() . '-meta'), $meta);
        $this->build->storeMeta((self::pluginName() . '-data'), $output);
        $this->build->storeMeta((self::pluginName() . '-errors'), $parser->getTotalFailures());

        return $success;
    }
}
