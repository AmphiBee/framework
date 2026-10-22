<?php

namespace Themosis\Tests\Core\Enums;

use PHPUnit\Framework\TestCase;
use Themosis\Core\Enums\PostStatus;

class PostStatusTest extends TestCase
{
    public function test_values_match_word_press_post_statuses()
    {
        $this->assertSame('publish', PostStatus::publish()->value);
        $this->assertSame('auto-draft', PostStatus::autoDraft()->value);
        $this->assertTrue(PostStatus::from('auto-draft')->equals(PostStatus::autoDraft()));
    }
}
