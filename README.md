# RabbitMqScalerBundle

Provides a command to launch RabbitMq consumers when the need arises.

## About ##

The RabbitMqScalerBundle extends php-amqplib's RabbitMqBundle by adding a command that handles launching your consumers when they are needed.

```bash
$ ./app/console michelv:rabbitmq:scaler your_queue
```

Every N seconds (default is N=10), the command will check whether there are enough workers to handle the number of messages in the given queue.

The command inherits from rabbitmq:consumer, so that any argument for that command will be applied to the command that launches a consumer.

Consumers are launched, but not terminated. This is something that can be done automatically by setting a connection timeout in your consumer's configuration, or by specifying a number of messages to handle using the ```--messages``` option.

## Installation ##

Require the bundle and its dependencies with composer:

```bash
$ composer require michelv/rabbitmq-scaler-bundle=dev-master
```

Register the bundle and the bundle that it depends on:

```php
// app/AppKernel.php

public function registerBundles()
{
    $bundles = array(
        // ...
        new RCH\ConfigAccessBundle\RCHConfigAccessBundle(),
        new Michelv\RabbitMqScalerBundle\MichelvRabbitMqScalerBundle(),
    );
}
```

## Configuration ##

### Queue options ###

The command will regularly query the RabbitMQ server for the state of the queue being consumed.
For this purpose, it needs to know the exact options that you have set up with php-amqplib's RabbitMqBundle.

For example, with the following configuration:

```yaml
old_sound_rabbit_mq:
    ...
    consumers:
        upload_picture:
            connection:       default
            exchange_options: {name: 'upload-picture', type: direct}
            queue_options:    {name: 'upload-picture'}
            callback:         upload_picture_service
```

You will need to add this configuration:

```yaml
michelv_rabbit_mq_scaler:
    consumers:
        upload_picture:
            queue_options:    {name: 'upload-picture'}
```

### Defaults for the command ###

You can change the defaults in your configuration file.

The defaults are defined thusly:

```yaml
michelv_rabbit_mq_scaler:
    debug: '%kernel.debug%'
    min_consumers: 1
    max_consumers: 10
    messages: 10
    interval: 10
    iterations: 0
    command: 'rabbitmq:consumer'
    prefix: ''
    log: '/dev/null'
    consumer_service_pattern: 'old_sound_rabbit_mq.%s_consumer'
```

## Examples ##

No consumers when there are no tasks, a maximum amount of 20 consumers, check every 5 seconds:

```bash
$ ./app/console michelv:rabbitmq:scaler --min 0 --max 20 --interval 5 your_queue
```

Default settings, but each consumer must handle at most 50 messages, or quit when they have used over 100 MB of RAM:

```bash
$ ./app/console michelv:rabbitmq:scaler --messages 50 --memory-limit 100 your_queue
```

Default settings, but each consumer is niced, and the command will stop after 10 iterations:

```bash
$ ./app/console michelv:rabbitmq:scaler --prefix 'nice -n 15' --iterations 10 your_queue
```
