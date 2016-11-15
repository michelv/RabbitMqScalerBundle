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

    /**
     * Any option or argument defined BaseConsumerCommand will be passed
     * to the actual workers.
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->addOption('min', null, InputOption::VALUE_OPTIONAL, 'Minimum number of consumers', null)
            ->addOption('max', null, InputOption::VALUE_OPTIONAL, 'Maximum number of consumers', null)
            ->addOption('interval', 'i', InputOption::VALUE_OPTIONAL, 'Number of seconds between checks', null)
            ->addOption('iterations', null, InputOption::VALUE_OPTIONAL, 'Maximum number of iterations', null)
            ->addOption('command', null, InputOption::VALUE_OPTIONAL, 'Symfony command to run', null)
            ->addOption('prefix', 'p', InputOption::VALUE_OPTIONAL, 'Prefix for the command line', null)
            ->addOption('consumer_service_pattern', null, InputOption::VALUE_OPTIONAL, 'Pattern for the consumer service', null)
            ->addOption('log', null, InputOption::VALUE_OPTIONAL, 'Full path to a log file for the consumers\' output', null)
            ->setName('michelv:rabbitmq:scaler');
    }

    /**
     * Initial set up.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
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

    /**
     * Executes the command.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->initConsumer($input);
        $this->name = $input->getArgument('name');
        $this->debug = $this->getOption('debug');

        if ($this->debug) {
            $output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
        }

        return $this->main();
    }

    /**
     * Main loop
     * Here it will either loop as many iterations as requested and return 0,
     * or loop until you interrupt the command.
     *
     * @return int
     */
    protected function main()
    {
        $messages = $this->getOption('messages');
        $min = $this->getOption('min');
        $max = $this->getOption('max');
        $interval = $this->getOption('interval');
        $iterations = $this->getOption('iterations');
        $currentIteration = 1;

        do {
            $state = $this->getCurrentQueueState();
            $threshold = $messages * $state['consumers'];

            $this->log(sprintf('%d consumers, %d messages', $state['consumers'], $state['messages']));

            $consumersNeeded = 0;

            if ($state['messages'] > $threshold && $state['consumers'] < $max) {
                if ($state['consumers'] == 0) {
                    $consumersNeeded = max(1, $min);
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

            if ($iterations > 0 && $currentIteration == $iterations) {
                return 0;
            }

            ++$currentIteration;
            sleep($interval);
        } while (true);
    }

    /**
     * Write a message to the output if the command is run with verbosity.
     *
     * @param string $message
     */
    protected function log($message)
    {
        if ($this->output->isVerbose()) {
            $this->output->writeln(sprintf('%s %s', time(), $message));
        }
    }

    /**
     * Get an option's value, or fallback to its default value.
     *
     * @param string $name
     *
     * @return mixed
     */
    protected function getOption($name)
    {
        $value = $this->input->getOption($name);

        if ($value === null && isset($this->defaults[$name])) {
            $value = $this->defaults[$name];
        }

        return $value;
    }

    /**
     * Return the actual shell command that will launch a consumer.
     *
     * @return string
     */
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

    /**
     * Launch a consumer in the background with shell_exec, log the
     * shell command used and the consumer's PID.
     */
    protected function launchConsumer()
    {
        $command = $this->getShellCommand();

        $pid = (int) shell_exec(sprintf('%s 2>&1 & echo $!', $command));

        if ($this->debug) {
            $this->log(sprintf("PID: %d\tCMD: %s", $pid, $command));
        }
    }

    /**
     * Get the current state of the queue
     * Returns an array with keys 'messages' and 'consumers'.
     *
     * @return array
     */
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

    /**
     * Get the queue's options as defined in the config file
     * You need to copy the queue_options for each consumer in order to avoid
     * undefined behaviour.
     *
     * @return array
     */
    protected function getQueueOptions()
    {
        static $queueOptions = null;

        if ($queueOptions === null) {
            $consumers = $this->defaults['consumers'];

            if (empty($consumers)
                || !isset($consumers[$this->name])
                || !isset($consumers[$this->name]['queue_options'])
            ) {
                throw new \RuntimeException(sprintf('Please copy the queue_options for the queue %s', $this->name));
            }

            $queueOptions = $consumers[$this->name]['queue_options'];
        }

        return $queueOptions;
    }

    /**
     * Returns the sprintf pattern for the name of the consumer service
     * This is used internally by BaseConsumerCommand::initConsumer.
     *
     * @return string
     */
    protected function getConsumerService()
    {
        return $this->getOption('consumer_service_pattern');
    }
}
