<?php

namespace Michelv\RabbitMqScalerBundle\Command;

use OldSound\RabbitMqBundle\Command\BaseConsumerCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\Kernel;

class RabbitMqScalerCommand extends BaseConsumerCommand
{
    protected $name;
    protected $environment;
    protected $defaults;
    protected $rootDir;
    protected $consoleExecutable;
    protected $debug;
    protected $input;
    protected $output;

    protected function configure()
    {
        parent::configure();

        $this
            ->addOption('min_consumers', 'min', InputOption::VALUE_OPTIONAL, 'Minimum number of consumers', null)
            ->addOption('max_consumers', 'max', InputOption::VALUE_OPTIONAL, 'Maximum number of consumers', null)
            ->addOption('interval', 'i', InputOption::VALUE_OPTIONAL, 'Number of seconds between checks', null)
            ->addOption('command', null, InputOption::VALUE_OPTIONAL, 'Symfony command to run', null)
            ->addOption('prefix', 'p', InputOption::VALUE_OPTIONAL, 'Prefix for the command line', null)
            ->addOption('consumer_service_pattern', null, InputOption::VALUE_OPTIONAL, 'Pattern for the consumer service', null)
            ->addOption('queue_options_pattern', null, InputOption::VALUE_OPTIONAL, 'Pattern for the queue options', null)
            ->addOption('log', null, InputOption::VALUE_OPTIONAL, 'Full path to a log file for the consumers\' output', null)
            ->setName('michelv:rabbitmq:scaler');
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);

        $this->input = $input;
        $this->output = $output;
        $kernel = $this->container->get('kernel');

        $this->environment = $kernel->getEnvironment();
        $this->defaults = $this->container->getParameter('michelv_rabbit_mq_scaler');
        $this->rootDir = $kernel->getRootDir();
        $this->consoleExecutable = (Kernel::VERSION > 3) ? 'bin/console' : 'app/console';
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->initConsumer($input);
        $this->name = $input->getArgument('name');
        $this->debug = $this->getOption('debug');

        if ($this->debug) {
            $output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
        }

        $this->main();
    }

    protected function main()
    {
        $messages = $this->getOption('messages');
        $min = $this->getOption('min_consumers');
        $max = $this->getOption('max_consumers');
        $interval = $this->getOption('interval');

        do {
            $state = $this->getCurrentQueueState();
            $threshold = $messages * $state['consumers'];

            $this->log(sprintf('%d consumers, %d messages', $state['consumers'], $state['messages']));

            $consumersNeeded = 0;

            if ($state['messages'] > $threshold && $state['consumers'] < $max) {
                if ($state['consumers'] == 0) {
                    $consumersNeeded = $min;
                } else {
                    $consumersNeeded = $max - $state['consumers'];
                }

                $this->log(sprintf('Not enough consumers to handle the %d messages, adding %d.', $state['messages'], $consumersNeeded));
            } elseif ($state['consumers'] < $min) {
                $consumersNeeded = $min - $state['consumers'];

                $this->log(sprintf('Less than %d consumers, adding %d.', $min, $consumersNeeded));
            }

            for ($i = 0; $i < $consumersNeeded; ++$i) {
                $this->launchConsumer();
            }

            sleep($interval);
        } while (true);
    }

    protected function log($message)
    {
        if ($this->output->isVerbose()) {
            $this->output->writeln(sprintf('%s %s', time(), $message));
        }
    }

    protected function getOption($name)
    {
        $value = $this->input->getOption($name);

        if ($value === null && isset($this->defaults[$name])) {
            $value = $this->defaults[$name];
        }

        return $value;
    }

    protected function getShellCommand()
    {
        static $command = null;

        if ($command === null) {
            // options inherited from BaseConsumerCommand
            $arguments = [
                '--messages='.escapeshellarg($this->getOption('messages')),
                '--route='.escapeshellarg($this->getOption('route')),
            ];

            if ($this->getOption('memory-limit') !== null) {
                $arguments[] = '--memory-limit='.escapeshellarg($this->getOption('memory-limit'));
            }

            foreach (['debug', 'without-signals'] as $argumentName) {
                if ($this->getOption($argumentName)) {
                    $arguments[] = '--'.$argumentName;
                }
            }

            $command = sprintf(
                '%s %s/../%s %s --env=%s %s %s >> %s',
                escapeshellcmd($this->getOption('prefix')),
                $this->rootDir,
                escapeshellarg($this->consoleExecutable),
                escapeshellarg($this->getOption('command')),
                escapeshellarg($this->environment),
                implode(' ', $arguments),
                escapeshellarg($this->name),
                escapeshellarg($this->getOption('log'))
            );
        }

        return $command;
    }

    protected function launchConsumer()
    {
        $command = $this->getShellCommand();

        $started_at = time();
        shell_exec(sprintf('%s 2>&1 & echo $!', $command));
    }

    protected function getCurrentQueueState()
    {
        $queueOptions = $this->getQueueOptions();

        list(, $nb_messages, $nb_consumers) = $this->consumer->getChannel()->queue_declare(
            $queueOptions['name'],
            $queueOptions['passive'],
            $queueOptions['durable'],
            $queueOptions['exclusive'],
            $queueOptions['auto_delete'],
            $queueOptions['nowait'],
            $queueOptions['arguments'],
            $queueOptions['ticket']
        );

        return [
            'messages' => $nb_messages,
            'consumers' => $nb_consumers,
        ];
    }

    protected function getQueueOptions()
    {
        static $queueOptions = null;

        if ($queueOptions === null) {
            $accessor = $this->getContainer()->get('rch_config_access.accessor');

            // get the exact way that the queue was declared on the server
            $queueOptions = $accessor->get(sprintf($this->getOption('queue_options_pattern'), $this->name));
        }

        return $queueOptions;
    }

    protected function getConsumerService()
    {
        return $this->getOption('consumer_service_pattern');
    }
}
