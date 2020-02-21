<?php
declare(strict_types=1);

namespace ArrayIterator\Service\Extension\OcrExtension;

use ArrayIterator\Service\Core\Module\AbstractExtension;

/**
 * Class OcrExtension
 * @package ArrayIterator\Service\Extension\OcrExtension
 */
class OcrExtension extends AbstractExtension
{
    /**
     * {@inheritDoc}
     */
    public function registerAllRoutePath(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    protected function afterModulesInit()
    {
        $this->addControllerByPath(__DIR__.'/Controller');
    }
}
