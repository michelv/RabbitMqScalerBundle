<?php

namespace Michelv\RabbitMqScalerBundle\DependencyInjection;

use OldSound\RabbitMqBundle\DependencyInjection\Configuration as BaseConfiguration;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration extends BaseConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder()
    {
        $tree = new TreeBuilder();

        $rootNode = $tree->root('michelv_rabbit_mq_scaler');

        $rootNode
            ->children()
                ->booleanNode('debug')->defaultValue('%kernel.debug%')->end()
                ->integerNode('min')->defaultValue(1)->end()
                ->integerNode('max')->defaultValue(10)->end()
                ->integerNode('messages')->defaultValue(10)->end()
                ->integerNode('interval')->defaultValue(10)->end()
                ->integerNode('iterations')->defaultValue(0)->end()
                ->scalarNode('command')->defaultValue('rabbitmq:consumer')->end()
                ->scalarNode('prefix')->defaultValue('')->end()
                ->scalarNode('consumer_service_pattern')->defaultValue('old_sound_rabbit_mq.%s_consumer')->end()
                ->scalarNode('log')->defaultValue('/dev/null')->end()
            ->end()
        ;

        $this->addConsumers($rootNode);

        return $tree;
    }

    protected function addConsumers(ArrayNodeDefinition $node)
    {
        $node
            ->fixXmlConfig('consumer')
            ->children()
                ->arrayNode('consumers')
                    ->canBeUnset()
                    ->useAttributeAsKey('key')
                    ->prototype('array')
                        ->append($this->getQueueConfiguration())
                    ->end()
                ->end()
            ->end()
        ;
    }
}
