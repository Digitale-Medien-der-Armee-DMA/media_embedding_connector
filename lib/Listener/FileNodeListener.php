<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Listener;

use OCA\MediaEmbeddingConnector\Service\AppConfig;
use OCA\MediaEmbeddingConnector\Service\AppAccessPolicy;
use OCA\MediaEmbeddingConnector\Service\ImageEligibilityService;
use OCA\MediaEmbeddingConnector\Service\IndexJobScheduler;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;

/** @template-implements IEventListener<NodeCreatedEvent|NodeWrittenEvent|NodeRenamedEvent|NodeDeletedEvent> */
class FileNodeListener implements IEventListener
{
    public function __construct(
        private AppConfig $config,
        private AppAccessPolicy $accessPolicy,
        private ImageEligibilityService $eligibilityService,
        private IndexJobScheduler $scheduler,
    ) {
    }

    public function handle(Event $event): void
    {
        if (!$this->config->isIndexingEnabled()) {
            return;
        }

        if ($event instanceof NodeDeletedEvent) {
            $node = $event->getNode();
            if ($node instanceof File) {
                $this->scheduler->enqueueDelete((string)$node->getId(), $node->getOwner()?->getUID());
            }
            return;
        }

        $node = $event instanceof NodeRenamedEvent ? $event->getTarget() : $event->getNode();
        if (!$node instanceof File || !$this->eligibilityService->isIndexingCandidate($node->getMimeType(), $node->getName())) {
            return;
        }
        $ownerUid = $node->getOwner()?->getUID();
        if ($ownerUid === null || !$this->accessPolicy->isUserIdAllowed($ownerUid)) {
            return;
        }

        $this->scheduler->enqueueIndex(
            (string)$node->getId(),
            $ownerUid,
            $node->getEtag(),
        );
    }
}
