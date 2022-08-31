<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Feature\NodeDuplication\Dto;

use Neos\ContentRepository\Core\Feature\NodeDuplication\Dto\NodeReferencesSnapshot;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\PropertyCollectionInterface;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateIdentifier;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateClassification;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\SerializedPropertyValues;

/**
 * Implementation detail of {@see CopyNodesRecursively}
 *
 * @internal You'll never create this class yourself; see {@see CopyNodesRecursively::createFromSubgraphAndStartNode()}
 */
final class NodeSubtreeSnapshot implements \JsonSerializable
{
    /**
     * @param NodeSubtreeSnapshot[] $childNodes
     */
    private function __construct(
        public readonly NodeAggregateIdentifier $nodeAggregateIdentifier,
        public readonly NodeTypeName $nodeTypeName,
        public readonly ?NodeName $nodeName,
        public readonly NodeAggregateClassification $nodeAggregateClassification,
        public readonly SerializedPropertyValues $propertyValues,
        public readonly NodeReferencesSnapshot $nodeReferences,
        public readonly array $childNodes
    ) {
        foreach ($childNodes as $childNode) {
            if (!$childNode instanceof NodeSubtreeSnapshot) {
                throw new \InvalidArgumentException(
                    'an element in $childNodes was not of type NodeSubtreeSnapshot, but ' . get_class($childNode)
                );
            }
        }
    }

    public static function fromSubgraphAndStartNode(ContentSubgraphInterface $subgraph, Node $sourceNode): self
    {
        $childNodes = [];
        foreach ($subgraph->findChildNodes($sourceNode->nodeAggregateIdentifier) as $sourceChildNode) {
            $childNodes[] = self::fromSubgraphAndStartNode($subgraph, $sourceChildNode);
        }
        /** @var PropertyCollectionInterface $properties */
        $properties = $sourceNode->properties;

        return new self(
            $sourceNode->nodeAggregateIdentifier,
            $sourceNode->nodeTypeName,
            $sourceNode->nodeName,
            $sourceNode->classification,
            $properties->serialized(),
            NodeReferencesSnapshot::fromReferences(
                $subgraph->findReferencedNodes($sourceNode->nodeAggregateIdentifier)
            ),
            $childNodes
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'nodeAggregateIdentifier' => $this->nodeAggregateIdentifier,
            'nodeTypeName' => $this->nodeTypeName,
            'nodeName' => $this->nodeName,
            'nodeAggregateClassification' => $this->nodeAggregateClassification,
            'propertyValues' => $this->propertyValues,
            'nodeReferences' => $this->nodeReferences,
            'childNodes' => $this->childNodes,
        ];
    }

    public function walk(\Closure $forEachElementFn): void
    {
        $forEachElementFn($this);
        foreach ($this->childNodes as $childNode) {
            $childNode->walk($forEachElementFn);
        }
    }

    /**
     * @param array<string,mixed> $array
     */
    public static function fromArray(array $array): self
    {
        $childNodes = [];
        foreach ($array['childNodes'] as $childNode) {
            $childNodes[] = self::fromArray($childNode);
        }

        return new self(
            NodeAggregateIdentifier::fromString($array['nodeAggregateIdentifier']),
            NodeTypeName::fromString($array['nodeTypeName']),
            isset($array['nodeName']) ? NodeName::fromString($array['nodeName']) : null,
            NodeAggregateClassification::from($array['nodeAggregateClassification']),
            SerializedPropertyValues::fromArray($array['propertyValues']),
            NodeReferencesSnapshot::fromArray($array['nodeReferences']),
            $childNodes
        );
    }
}