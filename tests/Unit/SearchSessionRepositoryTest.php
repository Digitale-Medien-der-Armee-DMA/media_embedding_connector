<?php

declare(strict_types=1);
namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\Db\SearchSessionRepository;
use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCA\MediaEmbeddingConnector\Tests\Support\InMemoryIndexJobDatabase;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

class SearchSessionRepositoryTest extends TestCase
{
    public function testSessionsAreBoundToOwnerAndHaveAHardExpiry(): void
    {
        $now = 1000;
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(static function () use (&$now): int { return $now; });
        $db = new InMemoryIndexJobDatabase();
        $repo = new SearchSessionRepository($db, $time);
        $session = $repo->create('alice', ['candidates' => [['file_id' => '42', 'score' => 0.9]]]);
        self::assertSame(1900, $session['expires_at']);
        self::assertSame('42', $repo->load('alice', $session['session_id'])['candidates'][0]['file_id']);
        self::assertNotSame($session['session_id'], array_values($db->rows)[0]['token_hash']);
        try { $repo->load('bob', $session['session_id']); self::fail('Another user must not load the session'); }
        catch (ExternalServiceException $e) { self::assertSame('search_session_expired', $e->getPublicCode()); }
        $now = 1900;
        try { $repo->load('alice', $session['session_id']); self::fail('Expired session must not load'); }
        catch (ExternalServiceException $e) { self::assertSame('search_session_expired', $e->getPublicCode()); }
        self::assertSame(1, $repo->purgeExpired());
        self::assertSame([], $db->rows);
    }

    public function testMalformedSessionTokenNeverQueriesDatabase(): void
    {
        $db = $this->createMock(\OCP\IDBConnection::class);
        $db->expects(self::never())->method('getQueryBuilder');
        $this->expectException(ExternalServiceException::class);
        (new SearchSessionRepository($db, $this->createMock(ITimeFactory::class)))->load('alice', '../other-session');
    }
}
