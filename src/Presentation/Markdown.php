<?php
declare(strict_types=1);

namespace StageCms\Presentation;

use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Extension\Table\TableExtension;

final readonly class Markdown
{
    private CommonMarkConverter $converter;

    public function __construct()
    {
        $this->converter = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 30,
            'max_delimiters_per_line' => 1000,
            'table' => ['wrap' => ['enabled' => true, 'attributes' => ['class' => 'table-scroll']]],
        ]);
        $this->converter->getEnvironment()->addExtension(new TableExtension());
    }

    public function render(string $source): string
    {
        return $this->converter->convert($source)->getContent();
    }
}
