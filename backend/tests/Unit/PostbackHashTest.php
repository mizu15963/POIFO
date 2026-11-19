<?php

namespace Tests\Unit;

use App\Services\GfRewards\PostbackHandler;
use PHPUnit\Framework\TestCase;

class PostbackHashTest extends TestCase
{
    public function test_hash_is_hmac_sha256_of_user_action_and_datetime(): void
    {
        // 自前で作ったテスト値（期待値は hash_hmac('sha256', 'member-01:action-42:2026-11-20T09:30:00+09:00', 'poifo-test-key')）
        $hash = PostbackHandler::hash('poifo-test-key', [
            'user_id' => 'member-01',
            'cv_action_id' => 'action-42',
            'cv_datetime' => '2026-11-20T09:30:00+09:00',
        ]);

        $this->assertSame('33944bdeecea1995ffacb714ae22f6a2203a0f96081c59f194ad8817e89ce555', $hash);
    }
}
