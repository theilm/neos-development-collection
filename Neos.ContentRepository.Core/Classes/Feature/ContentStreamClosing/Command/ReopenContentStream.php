<?php

/*
 * This file is part of the Neos.ContentRepository.Core  package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Feature\ContentStreamClosing\Command;

use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamStatus;

/**
 * @internal implementation detail. You must not use this command directly.
 * Direct use may lead to hard to revert senseless state in your content repository.
 * Please use the higher level workspace commands instead.
 */
final readonly class ReopenContentStream implements CommandInterface
{
    /**
     * @param ContentStreamId $contentStreamId The id of the content stream to reopen
     * @param ContentStreamStatus $previousState The state the content stream was in before closing and is to be reset to
     */
    private function __construct(
        public ContentStreamId $contentStreamId,
        public ContentStreamStatus $previousState
    ) {
    }

    /**
     * @param ContentStreamId $contentStreamId The id of the content stream to reopen
     * @param ContentStreamStatus $previousState The state the content stream was in before closing and is to be reset to
     */
    public static function create(
        ContentStreamId $contentStreamId,
        ContentStreamStatus $previousState
    ): self {
        return new self(
            $contentStreamId,
            $previousState
        );
    }

    /**
     * @param array<string,string> $array
     * @internal only used for testcases
     */
    public static function fromArray(array $array): self
    {
        return new self(
            ContentStreamId::fromString($array['contentStreamId']),
            ContentStreamStatus::from($array['previousState']),
        );
    }
}
