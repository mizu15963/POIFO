<?php

namespace Tests\Unit;

use App\Services\GfRewards\PostbackHandler;
use PHPUnit\Framework\TestCase;

class PostbackHashTest extends TestCase
{
    public function test_hash_matches_the_example_in_the_asp_spec(): void
    {
        $hash = PostbackHandler::hash('test', [
            'user_id' => 'user123',
            'cv_action_id' => 'gfr3715491',
            'cv_datetime' => '2021-01-01T00:00:00+09:00',
        ]);

        $this->assertSame('e64ab9be1d7a72aba954b05e371e1c0950f81051460151258ad9e4b4456e9fc4', $hash);
    }
}
