<?php

namespace Bluebarry\Bluebarry\Test\Unit\Block;

use Bluebarry\Bluebarry\Block\CollectionPage;
use PHPUnit\Framework\TestCase;

class CollectionPageTest extends TestCase
{
    public function testAnInlineScriptGetsTheNonce_AndADataBlockDoesNot(): void
    {
        $html = CollectionPage::withNonce('<script>a()</script><script type="application/json">{}</script><script type="application/ld+json">{}</script>', 'abc');

        $this->assertSame('<script nonce="abc">a()</script><script type="application/json">{}</script><script type="application/ld+json">{}</script>', $html);
    }

    public function testAnotherScriptTypeOrAnAttributeNamedLikeTypeStillGetsIt(): void
    {
        $this->assertSame(
            '<script nonce="n" type="module"></script><script nonce="n" data-type="x"></script><scripts>',
            CollectionPage::withNonce('<script type="module"></script><script data-type="x"></script><scripts>', 'n')
        );
    }
}
