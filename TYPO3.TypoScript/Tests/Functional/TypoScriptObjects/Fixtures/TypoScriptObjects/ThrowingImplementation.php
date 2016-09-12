<?php
namespace TYPO3\TypoScript\Tests\Functional\TypoScriptObjects\Fixtures\TypoScriptObjects;

/*
 * This file is part of the TYPO3.TypoScript package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use TYPO3\TypoScript\Exception;
use TYPO3\TypoScript\TypoScriptObjects\AbstractTypoScriptObject;

class ThrowingImplementation extends AbstractTypoScriptObject
{
    /**
     * @return boolean
     */
    protected function getShouldThrow()
    {
        return $this->tsValue('shouldThrow');
    }

    /**
     * {@inheritdoc}
     */
    public function evaluate()
    {
        if ($this->getShouldThrow()) {
            throw new Exception('Just testing an exception', 1396557841);
        }
        return 'It depends';
    }
}
