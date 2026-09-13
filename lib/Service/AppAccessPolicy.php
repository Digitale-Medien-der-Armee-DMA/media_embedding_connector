<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;

class AppAccessPolicy
{
    public const APP_ID = 'media_embedding_connector';

    public function __construct(
        private IAppManager $appManager,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private IUserSession $userSession,
    ) {
    }

    public function isCurrentUserAllowed(): bool
    {
        return $this->isUserAllowed($this->userSession->getUser());
    }

    public function isUserAllowed(?IUser $user): bool
    {
        if (!$user instanceof IUser) {
            return false;
        }

        return $this->appManager->isEnabledForUser(self::APP_ID, $user);
    }

    public function isUserIdAllowed(string $userId): bool
    {
        $userId = trim($userId);
        if ($userId === '') {
            return false;
        }

        $restrictedGroups = $this->getRestrictedGroupIds();
        if ($restrictedGroups === []) {
            return true;
        }

        $user = $this->userManager->get($userId);
        if (!$user instanceof IUser) {
            return false;
        }
        if (!$this->isUserAllowed($user)) {
            return false;
        }

        foreach ($restrictedGroups as $groupId) {
            if ($this->groupManager->isInGroup($userId, $groupId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function getRestrictedGroupIds(): array
    {
        return array_values(array_filter(
            array_map('strval', $this->appManager->getAppRestriction(self::APP_ID)),
            static fn (string $groupId): bool => $groupId !== '',
        ));
    }
}
