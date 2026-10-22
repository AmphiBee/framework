<?php

namespace Themosis\Tests\Html;

use PHPUnit\Framework\TestCase;
use Themosis\Html\HtmlBuilder;

class HtmlBuilderTest extends TestCase
{
    public function test_html_builder_can_create_attributes()
    {
        $html = new HtmlBuilder;

        $this->assertEquals(
            'action="test" method="post"',
            $html->attributes([
                'action' => 'test',
                'method' => 'post',
            ]),
        );

        $this->assertEquals(
            'class="some-class" required checked min="0" max="255"',
            $html->attributes([
                'class' => 'some-class',
                'required',
                'checked',
                'min' => 0,
                'max' => 255,
            ]),
        );
    }

    public function test_html_builder_can_convert_characters_to_entities()
    {
        $html = new HtmlBuilder;

        $this->assertEquals(
            'Un &#039;apostrophe&#039; en &lt;strong&gt;gras&lt;/strong&gt;',
            $html->entities('Un \'apostrophe\' en <strong>gras</strong>'),
        );

        $this->assertEmpty($html->entities("\x8F!!!"));
    }

    public function test_html_builder_can_convert_special_characeters_to_html_entities()
    {
        $html = new HtmlBuilder;

        $this->assertEquals(
            '&lt;a href=&#039;test&#039;&gt;Test&lt;/a&gt;',
            $html->special("<a href='test'>Test</a>"),
        );
    }
}
