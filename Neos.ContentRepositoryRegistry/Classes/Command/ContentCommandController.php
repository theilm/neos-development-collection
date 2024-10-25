<?php declare(strict_types=1);

namespace Neos\ContentRepositoryRegistry\Command;

/*
 * This file is part of the Neos.ContentRepository package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
use Neos\ContentRepository\Core\Feature\NodeVariation\Exception\DimensionSpacePointIsAlreadyOccupied;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindRootNodeAggregatesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Exception\WorkspaceDoesNotExist;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Cli\CommandController;

final class ContentCommandController extends CommandController
{
    public function __construct(
        private readonly ContentRepositoryRegistry $contentRepositoryRegistry
    ) {
        parent::__construct();
    }

    /**
     * Creates node variants recursively from the source to the target dimension space point in the specified workspace and content repository.
     *
     * This can be necessary if a new content dimension specialization was added (for example a more specific language)
     *
     * *Note:* source and target dimensions have to be specified as JSON, for example:
     * ```
     * ./flow content:createvariantsrecursively '{"language": "de"}' '{"language": "de_ch"}'
     * ```
     *
     * @param string $source The JSON representation of the source dimension space point. (Example: '{"language": "de"}')
     * @param string $target The JSON representation of the target origin dimension space point.  (Example: '{"language": "en"}')
     * @param string $contentRepository The content repository identifier. (Default: 'default')
     * @param string $workspace The workspace name. (Default: 'live')
     */
    public function createVariantsRecursivelyCommand(string $source, string $target, string $contentRepository = 'default', string $workspace = WorkspaceName::WORKSPACE_NAME_LIVE): void
    {
        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $sourceSpacePoint = DimensionSpacePoint::fromJsonString($source);
        $targetSpacePoint = OriginDimensionSpacePoint::fromJsonString($target);
        $workspaceName = WorkspaceName::fromString($workspace);

        $contentRepositoryInstance = $this->contentRepositoryRegistry->get($contentRepositoryId);

        try {
            $sourceSubgraph = $contentRepositoryInstance->getContentGraph($workspaceName)->getSubgraph(
                $sourceSpacePoint,
                VisibilityConstraints::withoutRestrictions()
            );
        } catch (WorkspaceDoesNotExist) {
            $this->outputLine('<error>Workspace "%s" does not exist</error>', [$workspaceName->value]);
            $this->quit(1);
        }

        $this->outputLine('Creating <b>%s</b> to <b>%s</b> in workspace <b>%s</b> (content repository <b>%s</b>)', [$sourceSpacePoint->toJson(), $targetSpacePoint->toJson(), $workspaceName->value, $contentRepositoryId->value]);

        $rootNodeAggregates = $contentRepositoryInstance->getContentGraph($workspaceName)
            ->findRootNodeAggregates(FindRootNodeAggregatesFilter::create());


        foreach ($rootNodeAggregates as $rootNodeAggregate) {
            $this->createVariantRecursivelyInternal(
                0,
                $rootNodeAggregate->nodeAggregateId,
                $sourceSubgraph,
                $targetSpacePoint,
                $workspaceName,
                $contentRepositoryInstance,
            );
        }

        $this->outputLine('<success>Done!</success>');
    }

    private function createVariantRecursivelyInternal(int $level, NodeAggregateId $parentNodeAggregateId, ContentSubgraphInterface $sourceSubgraph, OriginDimensionSpacePoint $target, WorkspaceName $workspaceName, ContentRepository $contentRepository): void
    {
        $childNodes = $sourceSubgraph->findChildNodes(
            $parentNodeAggregateId,
            FindChildNodesFilter::create()
        );

        foreach ($childNodes as $childNode) {
            if ($childNode->classification->isRegular()) {
                $childNodeType = $contentRepository->getNodeTypeManager()->getNodeType($childNode->nodeTypeName);
                if ($childNodeType?->isOfType('Neos.Neos:Document')) {
                    $this->output("%s- %s\n", [
                        str_repeat('  ', $level),
                        $childNode->getProperty('uriPathSegment') ?? $childNode->aggregateId->value
                    ]);
                }
                try {
                    // Tethered nodes' variants are automatically created when the parent is translated.
                    $contentRepository->handle(CreateNodeVariant::create(
                        $workspaceName,
                        $childNode->aggregateId,
                        $childNode->originDimensionSpacePoint,
                        $target
                    ));
                } catch (DimensionSpacePointIsAlreadyOccupied $e) {
                    if ($childNodeType?->isOfType('Neos.Neos:Document')) {
                        $this->output("%s  (already exists)\n", [
                            str_repeat('  ', $level)
                        ]);
                    }
                }
            }

            $this->createVariantRecursivelyInternal(
                $level + 1,
                $childNode->aggregateId,
                $sourceSubgraph,
                $target,
                $workspaceName,
                $contentRepository
            );
        }
    }
}
