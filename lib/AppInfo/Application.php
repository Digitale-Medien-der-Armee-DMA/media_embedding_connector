<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\AppInfo;

use OCA\MediaEmbeddingConnector\Listener\FileNodeListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap
{
    public const APP_ID = 'media_embedding_connector';

    public function __construct(array $urlParams = [])
    {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void
    {
        $context->registerEventListener(NodeCreatedEvent::class, FileNodeListener::class);
        $context->registerEventListener(NodeWrittenEvent::class, FileNodeListener::class);
        $context->registerEventListener(NodeRenamedEvent::class, FileNodeListener::class);
        $context->registerEventListener(NodeDeletedEvent::class, FileNodeListener::class);
    }

    public function boot(IBootContext $context): void
    {
    }
}
