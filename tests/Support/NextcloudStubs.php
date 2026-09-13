<?php

declare(strict_types=1);

namespace OCP\DB\QueryBuilder {
    if (!interface_exists(IQueryBuilder::class)) {
        interface IQueryBuilder
        {
            public const PARAM_INT = 1;
            public const PARAM_INT_ARRAY = 101;
        }
    }
}

namespace OCP {
    if (!interface_exists(IUser::class)) {
        interface IUser
        {
            public function getUID();
        }
    }

    if (!interface_exists(IUserSession::class)) {
        interface IUserSession
        {
            public function getUser();
        }
    }

    if (!interface_exists(IUserManager::class)) {
        interface IUserManager
        {
            public function get(string $uid);

            public function getSeenUsers(int $offset, int $limit);
        }
    }

    if (!interface_exists(IGroupManager::class)) {
        interface IGroupManager
        {
            public function isInGroup($userId, $group);
        }
    }

    if (!interface_exists(IDBConnection::class)) {
        interface IDBConnection
        {
            public function getQueryBuilder();

            public function lockTable($tableName): void;

            public function unlockTable(): void;
        }
    }
}

namespace OCP\App {
    if (!interface_exists(IAppManager::class)) {
        interface IAppManager
        {
            public function isEnabledForUser($appId, $user = null);

            public function getAppRestriction(string $appId): array;
        }
    }
}

namespace OCP\Files {
    if (!interface_exists(IMimeTypeDetector::class)) {
        interface IMimeTypeDetector
        {
            public function detectContent($path);
        }
    }
}

namespace OCP\Http\Client {
    if (!interface_exists(IClientService::class)) {
        interface IClientService
        {
            public function newClient();
        }
    }

    if (!interface_exists(IClient::class)) {
        interface IClient
        {
            public function request(string $method, string $url, array $options = []);
        }
    }

    if (!interface_exists(IResponse::class)) {
        interface IResponse
        {
            public function getStatusCode();

            public function getBody();
        }
    }
}

namespace OCP\AppFramework\Utility {
    if (!interface_exists(ITimeFactory::class)) {
        interface ITimeFactory
        {
        }
    }
}

namespace OCP\AppFramework {
    if (!class_exists(App::class)) {
        class App
        {
            public function __construct(string $appName, array $urlParams = [])
            {
            }
        }
    }
}

namespace OCP\BackgroundJob {
    use OCP\AppFramework\Utility\ITimeFactory;

    if (!interface_exists(IJobList::class)) {
        interface IJobList
        {
            public function add($job, $argument = null): void;

            public function scheduleAfter(string $job, int $runAfter, $argument = null): void;
        }
    }

    if (!class_exists(QueuedJob::class)) {
        abstract class QueuedJob
        {
            protected ITimeFactory $time;

            public function __construct(ITimeFactory $time)
            {
                $this->time = $time;
            }

            protected function setAllowParallelRuns(bool $allow): void
            {
            }

            abstract protected function run(mixed $argument): void;
        }
    }
}

namespace Psr\Log {
    if (!interface_exists(LoggerInterface::class)) {
        interface LoggerInterface
        {
            /**
             * @param array<string, mixed> $context
             */
            public function error(string|\Stringable $message, array $context = []): void;

            /**
             * @param array<string, mixed> $context
             */
            public function info(string|\Stringable $message, array $context = []): void;

            /**
             * @param array<string, mixed> $context
             */
            public function warning(string|\Stringable $message, array $context = []): void;
        }
    }
}
