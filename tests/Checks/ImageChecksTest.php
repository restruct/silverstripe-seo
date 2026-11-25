<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

/**
 * Checks 11 and 12: alt text of the images in the rendered content, blocks included.
 */
class ImageChecksTest extends ChecksTestCase
{
    private function withImages(string $images): array
    {
        return ['Content' => static::GOOD_TEXT . '<p>' . $images . '</p>'];
    }

    public function testAltMissingPassesForDescribedAndDecorativeImages(): void
    {
        $page = $this->page($this->withImages(
            '<img src="/assets/a.jpg" alt="Two students at a pottery wheel">'
            . '<img src="/assets/line.png" alt="" role="presentation">'
            . '<img src="/assets/dots.png" alt="" aria-hidden="true">'
        ));
        $this->assertCheckPasses($page, 'image-alt-missing');
    }

    public function testAltMissingFailsAndListsTheImages(): void
    {
        $page = $this->page($this->withImages('<img src="/assets/no-alt.jpg">'), [
            ['Title' => 'Gallery', 'Content' => '<p><img src="/assets/empty-alt.jpg" alt=""></p>'],
        ]);
        $entry = $this->assertCheckFails($page, 'image-alt-missing', 'no-alt.jpg');
        # An empty alt without a decorative marker is reported too: editors leave it empty by accident
        $this->assertStringContainsString('empty-alt.jpg', implode("\n", $entry['details']));
    }

    public function testAltQualityPassesForGoodAltText(): void
    {
        $page = $this->page($this->withImages(
            '<img src="/assets/a.jpg" alt="Two students at a pottery wheel">'
            . '<img src="/assets/b.jpg" alt="A finished bowl drying on a shelf">'
        ));
        $this->assertCheckPasses($page, 'image-alt-quality');
    }

    /**
     * @dataProvider poorAltProvider
     */
    public function testAltQualityFailsForPoorAltText(string $images, string $inDetails): void
    {
        $this->assertCheckFails($this->page($this->withImages($images)), 'image-alt-quality', $inDetails);
    }

    public static function poorAltProvider(): array
    {
        return [
            'file name' => ['<img src="/assets/x.jpg" alt="IMG_4031.jpg">', 'IMG_4031.jpg'],
            'camera name' => ['<img src="/assets/x.jpg" alt="DSC01234">', 'DSC01234'],
            'image of' => ['<img src="/assets/x.jpg" alt="Image of a bowl on a shelf">', 'Image of'],
            'afbeelding van' => ['<img src="/assets/x.jpg" alt="Afbeelding van een schaal">', 'Afbeelding van'],
            'too short' => ['<img src="/assets/x.jpg" alt="Bowl">', 'Bowl'],
            'too long' => ['<img src="/assets/x.jpg" alt="' . str_repeat('A very long description ', 10) . '">', 'x.jpg'],
            'duplicate' => [
                '<img src="/assets/x.jpg" alt="A bowl on a shelf"><img src="/assets/y.jpg" alt="A bowl on a shelf">',
                'y.jpg',
            ],
            'stuffing' => [
                '<img src="/assets/x.jpg" alt="pottery course, pottery class, pottery workshop, pottery lessons">',
                'pottery',
            ],
        ];
    }
}
