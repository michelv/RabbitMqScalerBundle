<?php

namespace Michelv\RabbitMqScalerBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder()
    {
        $tree = new TreeBuilder();

        $rootNode = $tree->root('michelv_rabbit_mq_scaler');

        $rootNode
            ->children()
                ->booleanNode('debug')->defaultValue('%kernel.debug%')->end()
                ->integerNode('min_consumers_count')->defaultValue(1)->end()
                ->integerNode('max_consumers_count')->defaultValue(10)->end()
                ->integerNode('tasks_per_consumer_count')->defaultValue(10)->end()
                ->integerNode('check_interval')->defaultValue(10)->end()
                ->scalarNode('command')->defaultValue('bin/console rabbitmq:consumer')->end()
                ->scalarNode('consumer_service_pattern')->defaultValue('old_sound_rabbit_mq.%s_consumer')->end()
                ->scalarNode('queue_options_pattern')->defaultValue('old_sound_rabbit_mq.consumers.%s.queue_options')->end()
                ->scalarNode('log_file')->defaultValue('/dev/null')->end()
                ->booleanNode('log_append')->defaultValue(true)->end()
            ->end()
        ;

        return $tree;
    }
}
