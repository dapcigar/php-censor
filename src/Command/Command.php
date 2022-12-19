<?php

declare(strict_types=1);

namespace PHPCensor\Command;

use Monolog\Handler\AbstractHandler;
use Monolog\Logger;
use PHPCensor\Common\Application\ConfigurationInterface;
use PHPCensor\DatabaseManager;
use PHPCensor\Logging\OutputLogHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command as BaseCommand;

/**
 * @package    PHP Censor
 * @subpackage Application
 *
 * @author Dmitry Khomutov <poisoncorpsee@gmail.com>
 */
abstract class Command extends BaseCommand
{
    protected ConfigurationInterface $configuration;

    protected DatabaseManager $databaseManager;

    protected LoggerInterface $logger;

    public function __construct(
        ConfigurationInterface $configuration,
        DatabaseManager $databaseManager,
        LoggerInterface $logger,
        ?string $name = null
    ) {
        parent::__construct($name);

        $this->configuration   = $configuration;
        $this->databaseManager = $databaseManager;
        $this->logger          = $logger;
    }

    protected function configureLogging(OutputInterface $output): void
    {
        $level = LogLevel::ERROR;
        if ($output->isDebug()) {
            $level = LogLevel::DEBUG;
        } elseif ($output->isVeryVerbose()) {
            $level = LogLevel::INFO;
        } elseif ($output->isVerbose()) {
            $level = LogLevel::NOTICE;
        }

        if ($this->logger instanceof Logger) {
            $handlers = $this->logger->getHandlers();
            foreach ($handlers as $handler) {
                if ($handler instanceof AbstractHandler) {
                    $handler->setLevel($level);
                }
            }

            if (!$output->isQuiet()) {
                $this->logger->pushHandler(
                    new OutputLogHandler($output, $level)
                );
            }
        }

        if ($output->isDebug()) {
            $this->logger->notice(
                \sprintf('Command "%s" started in debug mode (-vvv).', $this->getName())
            );

            \define('DEBUG_MODE', true);
        }
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $this->configureLogging($output);

        return 0;
    }
}
