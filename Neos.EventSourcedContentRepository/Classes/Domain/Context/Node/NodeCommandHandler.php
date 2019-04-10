<?php
declare(strict_types=1);
namespace Neos\EventSourcedContentRepository\Domain\Context\Node;

/*
 * This file is part of the Neos.ContentRepository package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentGraph\DoctrineDbalAdapter\Domain\Projection\GraphProjector;
use Neos\ContentRepository\DimensionSpace\DimensionSpace\ContentDimensionZookeeper;
use Neos\ContentRepository\DimensionSpace\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\DimensionSpace\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\DimensionSpace\DimensionSpace\InterDimensionalVariationGraph;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Projection\Content\NodeInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Domain\ContentStream\ContentStreamIdentifier;
use Neos\ContentRepository\Domain\NodeAggregate\NodeAggregateIdentifier;
use Neos\ContentRepository\Domain\NodeType\NodeTypeName;
use Neos\ContentRepository\Exception\NodeConstraintException;
use Neos\ContentRepository\Exception\NodeExistsException;
use Neos\ContentRepository\Exception\NodeTypeNotFoundException;
use Neos\EventSourcedContentRepository\Domain\Context\ContentStream\ContentStreamEventStreamName;
use Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate\Command\CreateNodeAggregateWithNode;
use Neos\EventSourcedContentRepository\Domain\Context\Node\Command\HideNode;
use Neos\EventSourcedContentRepository\Domain\Context\Node\Command\MoveNode;
use Neos\EventSourcedContentRepository\Domain\Context\Node\Command\RemoveNodeAggregate;
use Neos\EventSourcedContentRepository\Domain\Context\Node\Command\RemoveNodesFromAggregate;
use Neos\EventSourcedContentRepository\Domain\Context\Node\Command\SetNodeProperty;
use Neos\EventSourcedContentRepository\Domain\Context\Node\Command\SetNodeReferences;
use Neos\EventSourcedContentRepository\Domain\Context\Node\Command\ShowNode;
use Neos\EventSourcedContentRepository\Domain\Context\Node\Event\NodeAggregateWasRemoved;
use Neos\EventSourcedContentRepository\Domain\Context\Node\Event\NodePropertyWasSet;
use Neos\EventSourcedContentRepository\Domain\Context\Node\Event\NodeReferencesWereSet;
use Neos\EventSourcedContentRepository\Domain\Context\Node\Event\NodesWereMoved;
use Neos\EventSourcedContentRepository\Domain\Context\Node\Event\NodesWereRemovedFromAggregate;
use Neos\EventSourcedContentRepository\Domain\Context\Node\Event\NodeWasHidden;
use Neos\EventSourcedContentRepository\Domain\Context\Node\Event\NodeWasShown;
use Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate\RelationDistributionStrategy;
use Neos\EventSourcedContentRepository\Domain\Context\Parameters\VisibilityConstraints;
use Neos\EventSourcedContentRepository\Domain\ValueObject\CommandResult;
use Neos\EventSourcedContentRepository\Domain\ValueObject\NodeMoveMapping;
use Neos\EventSourcedContentRepository\Domain\ValueObject\NodeMoveMappings;
use Neos\EventSourcedContentRepository\Exception;
use Neos\EventSourcedContentRepository\Exception\DimensionSpacePointNotFound;
use Neos\EventSourcedContentRepository\Exception\NodeNotFoundException;
use Neos\EventSourcedContentRepository\Service\Infrastructure\ReadSideMemoryCacheManager;
use Neos\EventSourcing\Event\Decorator\EventWithIdentifier;
use Neos\EventSourcing\Event\DomainEvents;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
final class NodeCommandHandler
{
    /**
     * @Flow\Inject
     * @var NodeEventPublisher
     */
    protected $nodeEventPublisher;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var InterDimensionalVariationGraph
     */
    protected $interDimensionalVariationGraph;

    /**
     * @Flow\Inject
     * @var ContentDimensionZookeeper
     */
    protected $contentDimensionZookeeper;

    /**
     * @Flow\Inject
     * @var \Neos\EventSourcedContentRepository\Domain\Projection\Content\ContentGraphInterface
     */
    protected $contentGraph;

    /**
     * @Flow\Inject
     * @var GraphProjector
     */
    protected $graphProjector;

    /**
     * @Flow\Inject
     * @var ReadSideMemoryCacheManager
     */
    protected $readSideMemoryCacheManager;

    /**
     * @param SetNodeProperty $command
     * @return CommandResult
     */
    public function handleSetNodeProperty(SetNodeProperty $command): CommandResult
    {
        $this->readSideMemoryCacheManager->disableCache();

        $events = null;
        $this->nodeEventPublisher->withCommand($command, function () use ($command, &$events) {
            $contentStreamIdentifier = $command->getContentStreamIdentifier();

            // Check if node exists
            // @todo: this must also work when creating a copy on write
            #$this->assertNodeWithOriginDimensionSpacePointExists($contentStreamIdentifier, $command->getNodeAggregateIdentifier(), $command->getOriginDimensionSpacePoint());

            $events = DomainEvents::withSingleEvent(
                EventWithIdentifier::create(
                    new NodePropertyWasSet(
                        $contentStreamIdentifier,
                        $command->getNodeAggregateIdentifier(),
                        $command->getOriginDimensionSpacePoint(),
                        $command->getPropertyName(),
                        $command->getValue()
                    )
                )
            );

            $this->nodeEventPublisher->publishMany(
                ContentStreamEventStreamName::fromContentStreamIdentifier($contentStreamIdentifier)->getEventStreamName(),
                $events
            );
        });
        return CommandResult::fromPublishedEvents($events);
    }

    /**
     * @param SetNodeReferences $command
     * @return CommandResult
     */
    public function handleSetNodeReferences(SetNodeReferences $command): CommandResult
    {
        $this->readSideMemoryCacheManager->disableCache();

        $events = null;
        $this->nodeEventPublisher->withCommand($command, function () use ($command, &$events) {
            $events = DomainEvents::withSingleEvent(
                EventWithIdentifier::create(
                    new NodeReferencesWereSet(
                        $command->getContentStreamIdentifier(),
                        $command->getSourceNodeAggregateIdentifier(),
                        $command->getSourceOriginDimensionSpacePoint(),
                        $command->getDestinationNodeAggregateIdentifiers(),
                        $command->getReferenceName()
                    )
                )
            );
            $this->nodeEventPublisher->publishMany(
                ContentStreamEventStreamName::fromContentStreamIdentifier($command->getContentStreamIdentifier())->getEventStreamName(),
                $events
            );
        });

        return CommandResult::fromPublishedEvents($events);
    }

    /**
     * @param HideNode $command
     * @return CommandResult
     */
    public function handleHideNode(HideNode $command): CommandResult
    {
        $this->readSideMemoryCacheManager->disableCache();

        $events = null;
        $this->nodeEventPublisher->withCommand($command, function () use ($command, &$events) {
            $contentStreamIdentifier = $command->getContentStreamIdentifier();

            // Soft constraint check: Check if node exists in *all* given DimensionSpacePoints
            foreach ($command->getAffectedDimensionSpacePoints() as $dimensionSpacePoint) {
                $this->assertNodeWithOriginDimensionSpacePointExists($contentStreamIdentifier, $command->getNodeAggregateIdentifier(), $dimensionSpacePoint);
            }


            $events = DomainEvents::withSingleEvent(
                EventWithIdentifier::create(
                    new NodeWasHidden(
                        $contentStreamIdentifier,
                        $command->getNodeAggregateIdentifier(),
                        $command->getAffectedDimensionSpacePoints()
                    )
                )
            );

            $this->nodeEventPublisher->publishMany(
                ContentStreamEventStreamName::fromContentStreamIdentifier($contentStreamIdentifier)->getEventStreamName(),
                $events
            );
        });
        return CommandResult::fromPublishedEvents($events);
    }

    /**
     * @param ShowNode $command
     * @return CommandResult
     */
    public function handleShowNode(ShowNode $command): CommandResult
    {
        $this->readSideMemoryCacheManager->disableCache();

        $events = null;
        $this->nodeEventPublisher->withCommand($command, function () use ($command, &$events) {
            $contentStreamIdentifier = $command->getContentStreamIdentifier();

            // Soft constraint check: Check if node exists in *all* given DimensionSpacePoints
            foreach ($command->getAffectedDimensionSpacePoints() as $dimensionSpacePoint) {
                $this->assertNodeWithOriginDimensionSpacePointExists($contentStreamIdentifier, $command->getNodeAggregateIdentifier(), $dimensionSpacePoint);
            }

            $events = DomainEvents::withSingleEvent(
                EventWithIdentifier::create(
                    new NodeWasShown(
                        $contentStreamIdentifier,
                        $command->getNodeAggregateIdentifier(),
                        $command->getAffectedDimensionSpacePoints()
                    )
                )
            );

            $this->nodeEventPublisher->publishMany(
                ContentStreamEventStreamName::fromContentStreamIdentifier($contentStreamIdentifier)->getEventStreamName(),
                $events
            );
        });
        return CommandResult::fromPublishedEvents($events);
    }

    /**
     * @param RemoveNodeAggregate $command
     * @return CommandResult
     */
    public function handleRemoveNodeAggregate(RemoveNodeAggregate $command): CommandResult
    {
        $this->readSideMemoryCacheManager->disableCache();

        $events = null;
        $this->nodeEventPublisher->withCommand($command, function () use ($command, &$events) {
            $contentStreamIdentifier = $command->getContentStreamIdentifier();

            // Check if node aggregate exists
            $nodeAggregate = $this->contentGraph->findNodeAggregateByIdentifier($contentStreamIdentifier, $command->getNodeAggregateIdentifier());
            if ($nodeAggregate === null) {
                throw new NodeAggregateNotFound('Node aggregate ' . $command->getNodeAggregateIdentifier() . ' not found', 1532026858);
            }

            $events = DomainEvents::withSingleEvent(
                EventWithIdentifier::create(
                    new NodeAggregateWasRemoved(
                        $contentStreamIdentifier,
                        $command->getNodeAggregateIdentifier()
                    )
                )
            );

            $this->nodeEventPublisher->publishMany(
                ContentStreamEventStreamName::fromContentStreamIdentifier($contentStreamIdentifier)->getEventStreamName(),
                $events
            );
        });
        return CommandResult::fromPublishedEvents($events);
    }

    /**
     * @param RemoveNodesFromAggregate $command
     * @return CommandResult
     * @throws SpecializedDimensionsMustBePartOfDimensionSpacePointSet
     */
    public function handleRemoveNodesFromAggregate(RemoveNodesFromAggregate $command): CommandResult
    {
        $this->readSideMemoryCacheManager->disableCache();

        foreach ($command->getDimensionSpacePointSet()->getPoints() as $point) {
            $specializations = $this->interDimensionalVariationGraph->getSpecializationSet($point, false);
            foreach ($specializations->getPoints() as $specialization) {
                if (!$command->getDimensionSpacePointSet()->contains($specialization)) {
                    throw new SpecializedDimensionsMustBePartOfDimensionSpacePointSet('The parent dimension ' . json_encode($point->getCoordinates()) . ' is in the given DimensionSpacePointSet, but its specialization ' . json_encode($specialization->getCoordinates()) . ' is not. This is currently not supported; and we might need to think through the implications of this case more before allowing it. There is no "technical hard reason" to prevent it; but to me (SK) it feels that it will lead to inconsistent behavior otherwise.',
                        1532154238);
                }
            }
        }

        $events = null;
        $this->nodeEventPublisher->withCommand($command, function () use ($command, &$events) {
            $contentStreamIdentifier = $command->getContentStreamIdentifier();

            // Check if node aggregate exists
            $nodeAggregate = $this->contentGraph->findNodeAggregateByIdentifier($contentStreamIdentifier, $command->getNodeAggregateIdentifier());
            if ($nodeAggregate === null) {
                throw new NodeAggregateNotFound('Node aggregate ' . $command->getNodeAggregateIdentifier() . ' not found', 1532026858);
            }

            $events = DomainEvents::withSingleEvent(
                EventWithIdentifier::create(
                    new NodesWereRemovedFromAggregate(
                        $contentStreamIdentifier,
                        $command->getNodeAggregateIdentifier(),
                        $command->getDimensionSpacePointSet()
                    )
                )
            );

            $this->nodeEventPublisher->publishMany(
                ContentStreamEventStreamName::fromContentStreamIdentifier($contentStreamIdentifier)->getEventStreamName(),
                $events
            );
        });
        return CommandResult::fromPublishedEvents($events);
    }

    private function assertNodeWithOriginDimensionSpacePointExists(ContentStreamIdentifier $contentStreamIdentifier, NodeAggregateIdentifier $nodeAggregateIdentifier, DimensionSpacePoint $originDimensionSpacePoint): NodeInterface
    {
        $subgraph = $this->contentGraph->getSubgraphByIdentifier($contentStreamIdentifier, $originDimensionSpacePoint, VisibilityConstraints::withoutRestrictions());
        $node = $subgraph->findNodeByNodeAggregateIdentifier($nodeAggregateIdentifier);
        if ($node === null) {
            throw new NodeNotFoundException(sprintf('Node %s not found in dimension %s', $nodeAggregateIdentifier, $originDimensionSpacePoint), 1541070463);
        }

        if (!$node->getOriginDimensionSpacePoint()->equals($originDimensionSpacePoint)) {
            throw new Exception\NodeNotOriginatingInCorrectDimensionSpacePointException(sprintf('Node %s has origin dimension space point %s, but you requested OriginDimensionSpacePoint %s.', $nodeAggregateIdentifier,
                $node->getOriginDimensionSpacePoint(), $originDimensionSpacePoint), 1541070670);
        }

        return $node;
    }
}
