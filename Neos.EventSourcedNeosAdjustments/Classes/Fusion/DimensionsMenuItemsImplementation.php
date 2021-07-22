<?php
declare(strict_types=1);
namespace Neos\EventSourcedNeosAdjustments\Fusion;

/*
 * This file is part of the Neos.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\DimensionSpace\Dimension\ContentDimensionIdentifier;
use Neos\ContentRepository\DimensionSpace\Dimension\ContentDimensionSourceInterface;
use Neos\ContentRepository\DimensionSpace\DimensionSpace\ContentDimensionZookeeper;
use Neos\ContentRepository\DimensionSpace\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\DimensionSpace\DimensionSpace\InterDimensionalVariationGraph;
use Neos\EventSourcedContentRepository\ContentAccess\NodeAccessorManager;
use Neos\EventSourcedContentRepository\Domain\Projection\Content\NodeInterface;
use Neos\ContentRepository\Domain\NodeAggregate\NodeAggregateIdentifier;
use Neos\EventSourcedContentRepository\Domain\Context\Parameters\VisibilityConstraints;
use Neos\Flow\Annotations as Flow;

/**
 * Fusion implementation for a dimensions menu.
 *
 * The items generated by this menu will be all possible variants (according to the configured dimensions
 * and presets) of the given node (including the given node).
 *
 * If a 'dimension' is configured via Fusion, only those variants of the the current subgraph
 * that match its other dimension values will be evaluated
 *
 * Main Options:
 * - dimension (optional, string): Name of the dimension which this menu should be limited to. Example: "language".
 * - values (optional, array): If set, only the given dimension values for the given dimension will be evaluated
 * - includeAllPresets (optional, bool): If set, generalizations in the other dimensions will be evaluated additionally if necessary to fetch a result for a given dimension value
 */
class DimensionsMenuItemsImplementation extends AbstractMenuItemsImplementation
{
    /**
     * @Flow\Inject
     * @var ContentDimensionZookeeper
     */
    protected $contentDimensionZookeeper;

    /**
     * @Flow\Inject
     * @var ContentDimensionSourceInterface
     */
    protected $contentDimensionSource;

    /**
     * @Flow\Inject
     * @var InterDimensionalVariationGraph
     */
    protected $interDimensionalVariationGraph;

    /**
     * @Flow\Inject
     * @var NodeAccessorManager
     */
    protected $nodeAccessorManager;

    /**
     * @var NodeInterface
     */
    protected $currentNode;


    /**
     * Builds the array of Menu items for this variant menu
     */
    protected function buildItems()
    {
        $menuItems = [];


        $currentDimensionSpacePoint = $this->currentNode->getDimensionSpacePoint();
        foreach ($this->contentDimensionZookeeper->getAllowedDimensionSubspace()->getPoints() as $dimensionSpacePoint) {
            $variant = null;
            if ($this->isDimensionSpacePointRelevant($dimensionSpacePoint)) {
                if ($dimensionSpacePoint->equals($currentDimensionSpacePoint)) {
                    $variant = $this->currentNode;
                } else {
                    $nodeAccessor = $this->nodeAccessorManager->accessorFor($this->currentNode->getContentStreamIdentifier(), $dimensionSpacePoint, VisibilityConstraints::frontend());
                    $variant = $nodeAccessor->findByIdentifier($this->currentNode->getNodeAggregateIdentifier());
                }

                if (!$variant && $this->includeGeneralizations()) {
                    $variant = $this->findClosestGeneralizationMatchingDimensionValue(
                        $dimensionSpacePoint,
                        $this->getContentDimensionIdentifierToLimitTo(),
                        $this->currentNode->getNodeAggregateIdentifier()
                    );
                }

                $metadata = $this->determineMetadata($dimensionSpacePoint);

                if ($variant === null || !$this->isNodeHidden($variant)) {
                    $menuItems[] = [
                        'node' => $variant,
                        'state' => $this->calculateItemState($variant),
                        'label' => $this->determineLabel($variant, $metadata),
                        'targetDimensions' => $metadata
                    ];
                }
            }
        }


        if ($this->getContentDimensionIdentifierToLimitTo() && $this->getValuesToRestrictTo()) {
            $order = array_flip($this->getValuesToRestrictTo());
            usort($menuItems, function (array $menuItemA, array $menuItemB) use ($order) {
                return $order[$menuItemA['node']->getDimensionSpacePoint()->getCoordinate($this->getContentDimensionIdentifierToLimitTo())]
                    <=> $order[$menuItemB['node']->getDimensionSpacePoint()->getCoordinate($this->getContentDimensionIdentifierToLimitTo())];
            });
        }

        return $menuItems;
    }

    /**
     * @param DimensionSpacePoint $dimensionSpacePoint
     * @return bool
     */
    protected function isDimensionSpacePointRelevant(DimensionSpacePoint $dimensionSpacePoint): bool
    {
        return !$this->getContentDimensionIdentifierToLimitTo() // no limit to one dimension, so all DSPs are relevant
            || $dimensionSpacePoint->equals($this->currentNode->getDimensionSpacePoint()) // always include the current variant
            // include all direct variants in the dimension we're limited to unless their values in that dimension are missing in the specified list
            || $dimensionSpacePoint->isDirectVariantInDimension($this->currentNode->getDimensionSpacePoint(), $this->getContentDimensionIdentifierToLimitTo())
            && (empty($this->getValuesToRestrictTo()) || in_array($dimensionSpacePoint->getCoordinate($this->getContentDimensionIdentifierToLimitTo()), $this->getValuesToRestrictTo()));
    }

    /**
     * @param DimensionSpacePoint $dimensionSpacePoint
     * @param ContentDimensionIdentifier $contentDimensionIdentifier
     * @param NodeAggregateIdentifier $nodeAggregateIdentifier
     * @return NodeInterface|null
     */
    protected function findClosestGeneralizationMatchingDimensionValue(
        DimensionSpacePoint $dimensionSpacePoint,
        ContentDimensionIdentifier $contentDimensionIdentifier,
        NodeAggregateIdentifier $nodeAggregateIdentifier
    ): ?NodeInterface {
        $generalizations = $this->interDimensionalVariationGraph->getWeightedGeneralizations($dimensionSpacePoint);
        ksort($generalizations);
        foreach ($generalizations as $generalization) {
            if ($generalization->getCoordinate($contentDimensionIdentifier) === $dimensionSpacePoint->getCoordinate($contentDimensionIdentifier)) {
                $nodeAccessor = $this->nodeAccessorManager->accessorFor($this->currentNode->getContentStreamIdentifier(), $generalization, VisibilityConstraints::frontend());
                $variant = $nodeAccessor->findByIdentifier($nodeAggregateIdentifier);
                if ($variant) {
                    return $variant;
                }
            }
        }

        return null;
    }

    /**
     * @param DimensionSpacePoint $dimensionSpacePoint
     * @return array
     */
    protected function determineMetadata(DimensionSpacePoint $dimensionSpacePoint): array
    {
        $metadata = $dimensionSpacePoint->getCoordinates();
        array_walk($metadata, function (&$dimensionValue, $rawDimensionIdentifier) {
            $dimensionIdentifier = new ContentDimensionIdentifier($rawDimensionIdentifier);
            $dimensionValue = [
                'value' => $dimensionValue,
                'label' => $this->contentDimensionSource->getDimension($dimensionIdentifier)->getValue($dimensionValue)->getConfigurationValue('label'),
                'isPinnedDimension' => (!$this->getContentDimensionIdentifierToLimitTo() || $dimensionIdentifier->equals($this->getContentDimensionIdentifierToLimitTo()))
            ];
        });

        return $metadata;
    }

    /**
     * @param NodeInterface|null $variant
     * @param array $metadata
     * @return string
     */
    protected function determineLabel(NodeInterface $variant = null, array $metadata): string
    {
        if ($this->getContentDimensionIdentifierToLimitTo()) {
            return $metadata[(string)$this->getContentDimensionIdentifierToLimitTo()]['label'] ?: '';
        } else {
            if ($variant) {
                return $variant->getLabel() ?: '';
            } else {
                return array_reduce($metadata, function ($carry, $item) {
                    return $carry . (empty($carry) ? '' : '-') . $item['label'];
                }, '');
            }
        }
    }

    /**
     * @param NodeInterface|null $variant
     * @return string
     */
    protected function calculateItemState(NodeInterface $variant = null): string
    {
        if (is_null($variant)) {
            return self::STATE_ABSENT;
        }

        if ($variant === $this->currentNode) {
            return self::STATE_CURRENT;
        }

        return self::STATE_NORMAL;
    }

    /**
     * In some cases generalization of the other dimension values is feasible
     * to find a dimension space point in which a variant can be resolved
     * @return bool
     */
    protected function includeGeneralizations(): bool
    {
        return $this->getContentDimensionIdentifierToLimitTo() && $this->fusionValue('includeAllPresets');
    }

    /**
     * @return ContentDimensionIdentifier|null
     */
    protected function getContentDimensionIdentifierToLimitTo(): ?ContentDimensionIdentifier
    {
        return $this->fusionValue('dimension') ? new ContentDimensionIdentifier($this->fusionValue('dimension')) : null;
    }

    /**
     * @return array
     */
    protected function getValuesToRestrictTo(): array
    {
        return $this->fusionValue('values') ?? ($this->fusionValue('presets') ?? []);
    }
}
