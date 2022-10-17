<?php
declare(strict_types=1);

namespace Elephox\Miniphox\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_FUNCTION)]
class Delete extends HttpMethodAttribute
{
    public function __construct(string $path = '')
    {
        parent::__construct("DELETE", $path);
    }
}
