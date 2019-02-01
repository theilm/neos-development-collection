<?php
declare(strict_types=1);
namespace Neos\EventSourcedNeosAdjustments\Ui\Domain\Model\Changes;

/*
 * This file is part of the Neos.Neos.Ui package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\DimensionSpace\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Projection\Content\TraversableNodeInterface;
use Neos\ContentRepository\Domain\Service\NodeServiceInterface;
use Neos\EventSourcedContentRepository\Domain\Context\Node\Command\HideNode;
use Neos\EventSourcedContentRepository\Domain\Context\Node\Command\SetNodeProperty;
use Neos\EventSourcedContentRepository\Domain\Context\Node\Command\ShowNode;
use Neos\EventSourcedContentRepository\Domain\Context\Node\NodeCommandHandler;
use Neos\EventSourcedContentRepository\Domain\ValueObject\PropertyValue;
use Neos\EventSourcedNeosAdjustments\Ui\Domain\Model\AbstractChange;
use Neos\EventSourcedNeosAdjustments\Ui\Domain\Model\Feedback\Operations\ReloadContentOutOfBand;
use Neos\EventSourcedNeosAdjustments\Ui\Domain\Model\Feedback\Operations\UpdateNodeInfo;
use Neos\EventSourcedNeosAdjustments\Ui\Service\NodePropertyConversionService;
use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Neos\Ui\Domain\Model\RenderedNodeDomAddress;
use Neos\Utility\ObjectAccess;

/**
 * Changes a property on a node
 */
class Property extends AbstractChange
{

    /**
     * @Flow\Inject
     * @var NodePropertyConversionService
     */
    protected $nodePropertyConversionService;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var NodeServiceInterface
     */
    protected $nodeService;

    /**
     * The node dom address
     *
     * @var RenderedNodeDomAddress
     */
    protected $nodeDomAddress;

    /**
     * The name of the property to be changed
     *
     * @var string
     */
    protected $propertyName;

    /**
     * The value, the property will be set to
     *
     * @var string
     */
    protected $value;

    /**
     * The change has been initiated from the inline editing
     *
     * @var bool
     */
    protected $isInline;

    /**
     * @Flow\Inject
     * @var NodeCommandHandler
     */
    protected $nodeCommandHandler;

    /**
     * Set the property name
     *
     * @param string $propertyName
     * @return void
     */
    public function setPropertyName($propertyName)
    {
        $this->propertyName = $propertyName;
    }

    /**
     * Get the property name
     *
     * @return string
     */
    public function getPropertyName()
    {
        return $this->propertyName;
    }

    /**
     * Set the node dom address
     *
     * @param RenderedNodeDomAddress $nodeDomAddress
     * @return void
     */
    public function setNodeDomAddress(RenderedNodeDomAddress $nodeDomAddress = null)
    {
        $this->nodeDomAddress = $nodeDomAddress;
    }

    /**
     * Get the node dom address
     *
     * @return RenderedNodeDomAddress
     */
    public function getNodeDomAddress()
    {
        return $this->nodeDomAddress;
    }

    /**
     * Set the value
     *
     * @param string $value
     */
    public function setValue($value)
    {
        $this->value = $value;
    }

    /**
     * Get the value
     *
     * @return string
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * Set isInline
     *
     * @param bool $isInline
     */
    public function setIsInline($isInline)
    {
        $this->isInline = $isInline;
    }

    /**
     * Get isInline
     *
     * @return bool
     */
    public function getIsInline()
    {
        return $this->isInline;
    }

    /**
     * Checks whether this change can be applied to the subject
     *
     * @return boolean
     */
    public function canApply()
    {
        $nodeType = $this->getSubject()->getNodeType();
        $propertyName = $this->getPropertyName();
        $nodeTypeProperties = $nodeType->getProperties();

        return isset($nodeTypeProperties[$propertyName]);
    }

    /**
     * Applies this change
     *
     * @return void
     */
    public function apply()
    {
        if ($this->canApply()) {
            $node = $this->getSubject();
            $propertyName = $this->getPropertyName();
            $value = $this->nodePropertyConversionService->convert(
                $node->getNodeType(),
                $propertyName,
                $this->getValue()
            );

            // TODO: Make changing the node type a separated, specific/defined change operation.
            if ($propertyName === '_nodeType') {
                throw new \Exception("TODO FIX");
                $nodeType = $this->nodeTypeManager->getNodeType($value);
                $node = $this->changeNodeType($node, $nodeType);
            } elseif ($propertyName{0} === '_') {
                if ($propertyName === '_hidden') {
                    if ($value === true) {
                        $command = new HideNode(
                            $node->getContentStreamIdentifier(),
                            $node->getNodeAggregateIdentifier(),
                            // TODO: what do we want to hide? I.e. including NESTED dimensions?
                            new DimensionSpacePointSet([$node->getOriginDimensionSpacePoint()])
                        );
                        $this->nodeCommandHandler->handleHideNode($command);
                    } else {
                        // unhide
                        $command = new ShowNode(
                            $node->getContentStreamIdentifier(),
                            $node->getNodeAggregateIdentifier(),
                            // TODO: what do we want to unhide? I.e. including NESTED dimensions?
                            new DimensionSpacePointSet([$node->getOriginDimensionSpacePoint()])
                        );
                        $this->nodeCommandHandler->handleShowNode($command);
                    }
                } else {
                    throw new \Exception("TODO FIX");
                    ObjectAccess::setProperty($node, substr($propertyName, 1), $value);
                }
            } else {
                $propertyType = $this->nodeTypeManager->getNodeType((string)$node->getNodeType())->getPropertyType($propertyName);
                $command = new SetNodeProperty(
                    $node->getContentStreamIdentifier(),
                    $node->getNodeAggregateIdentifier(),
                    $node->getOriginDimensionSpacePoint(),
                    $propertyName,
                    new PropertyValue($value, $propertyType)
                );
                $this->nodeCommandHandler->handleSetNodeProperty($command);
            }

            $this->updateWorkspaceInfo();

            $reloadIfChangedConfigurationPath = sprintf('properties.%s.ui.reloadIfChanged', $propertyName);
            if (!$this->getIsInline() && $node->getNodeType()->getConfiguration($reloadIfChangedConfigurationPath)) {
                if ($this->getNodeDomAddress() && $this->getNodeDomAddress()->getFusionPath() && $node->findParentNode()->getNodeType()->isOfType('Neos.Neos:ContentCollection')) {
                    $reloadContentOutOfBand = new ReloadContentOutOfBand();
                    $reloadContentOutOfBand->setNode($node);
                    $reloadContentOutOfBand->setNodeDomAddress($this->getNodeDomAddress());
                    $this->feedbackCollection->add($reloadContentOutOfBand);
                } else {
                    $this->reloadDocument($node);
                }
            }

            $reloadPageIfChangedConfigurationPath = sprintf('properties.%s.ui.reloadPageIfChanged', $propertyName);
            if (!$this->getIsInline() && $node->getNodeType()->getConfiguration($reloadPageIfChangedConfigurationPath)) {
                $this->reloadDocument($node);
            }

            // This might be needed to update node label and other things that we can calculate only on the server
            $updateNodeInfo = new UpdateNodeInfo();
            $updateNodeInfo->setNode($node);
            $this->feedbackCollection->add($updateNodeInfo);
        }
    }

    /**
     * @param TraversableNodeInterface $node
     * @param NodeType $nodeType
     * @return TraversableNodeInterface
     */
    protected function changeNodeType(TraversableNodeInterface $node, NodeType $nodeType)
    {
        $oldNodeType = $node->getNodeType();
        ObjectAccess::setProperty($node, 'nodeType', $nodeType);
        $this->nodeService->cleanUpProperties($node);
        $this->nodeService->cleanUpAutoCreatedChildNodes($node, $oldNodeType);
        $this->nodeService->createChildNodes($node);

        return $node;
    }
}
