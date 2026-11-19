<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** api. 宛てのリクエストにする（本番で CloudFront Function が付けるヘッダー） */
    protected function asApiHost(): static
    {
        return $this->withHeader('x-poifo-viewer-host', 'api.poifo.test');
    }

    protected function asAdminHost(): static
    {
        return $this->withHeader('x-poifo-viewer-host', 'admin.poifo.test');
    }
}
