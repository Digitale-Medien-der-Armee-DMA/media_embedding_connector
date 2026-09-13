<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\Service\AppAccessPolicy;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class AppAccessPolicyTest extends TestCase
{
    public function testCurrentUserUsesNextcloudAppManagerDecision(): void
    {
        $user = $this->user('alice');
        [$policy, $appManager] = $this->policy($user, []);
        $appManager->expects(self::once())
            ->method('isEnabledForUser')
            ->with(AppAccessPolicy::APP_ID, $user)
            ->willReturn(true);

        self::assertTrue($policy->isCurrentUserAllowed());
    }

    public function testUnrestrictedAppAllowsKnownUserId(): void
    {
        [$policy, $appManager, $groupManager, $userManager] = $this->policy(null, []);
        $appManager->method('getAppRestriction')->willReturn([]);
        $userManager->expects(self::never())->method('get');
        $groupManager->expects(self::never())->method('isInGroup');

        self::assertTrue($policy->isUserIdAllowed('alice'));
    }

    public function testRestrictedAppAllowsUserIdInRestrictedGroup(): void
    {
        $user = $this->user('alice');
        [$policy, $appManager, $groupManager, $userManager] = $this->policy(null, ['media-ai']);
        $appManager->method('getAppRestriction')->willReturn(['media-ai']);
        $appManager->method('isEnabledForUser')->with(AppAccessPolicy::APP_ID, $user)->willReturn(true);
        $userManager->method('get')->with('alice')->willReturn($user);
        $groupManager->method('isInGroup')->with('alice', 'media-ai')->willReturn(true);

        self::assertTrue($policy->isUserIdAllowed('alice'));
    }

    public function testRestrictedAppDeniesUserIdOutsideRestrictedGroups(): void
    {
        $user = $this->user('bob');
        [$policy, $appManager, $groupManager, $userManager] = $this->policy(null, ['media-ai']);
        $appManager->method('getAppRestriction')->willReturn(['media-ai']);
        $appManager->method('isEnabledForUser')->with(AppAccessPolicy::APP_ID, $user)->willReturn(false);
        $userManager->method('get')->with('bob')->willReturn($user);
        $groupManager->expects(self::never())->method('isInGroup');

        self::assertFalse($policy->isUserIdAllowed('bob'));
    }

    /**
     * @param list<string> $groups
     * @return array{0: AppAccessPolicy, 1: IAppManager, 2: IGroupManager, 3: IUserManager}
     */
    private function policy(?IUser $currentUser, array $groups): array
    {
        $appManager = $this->createMock(IAppManager::class);
        $groupManager = $this->createMock(IGroupManager::class);
        $userManager = $this->createMock(IUserManager::class);
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($currentUser);
        $appManager->method('getAppRestriction')->willReturn($groups);

        return [
            new AppAccessPolicy($appManager, $groupManager, $userManager, $userSession),
            $appManager,
            $groupManager,
            $userManager,
        ];
    }

    private function user(string $uid): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }
}
