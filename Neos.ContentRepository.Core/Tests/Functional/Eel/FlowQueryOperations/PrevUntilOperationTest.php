<?php
namespace Neos\ContentRepository\Core\Tests\Functional\Eel\FlowQueryOperations;

/*
 * This file is part of the Neos.ContentRepository package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Eel\FlowQuery\FlowQuery;
use Neos\ContentRepository\Core\SharedModel\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Tests\Functional\AbstractNodeTest;

/**
 * Functional test case which tests FlowQuery PrevUntilOperation
 */
class PrevUntilOperationTest extends AbstractNodeTest
{
    /**
     * @return array
     */
    public function prevUntilOperationDataProvider()
    {
        return [
            [
                'currentNodePaths' => ['/a/a5'],
                'subject' => '[instanceof Neos.ContentRepository.Testing:NodeType]',
                'expectedNodePaths' => ['/a/a4'],
                'unexpectedNodePaths' => ['/a/a5','/a/a3','/a/a2']
            ],
            [
                'currentNodePaths' => ['/a/a3'],
                'subject' => '[instanceof Neos.ContentRepository.Testing:NodeType]',
                'expectedNodePaths' => ['/a/a2'],
                'unexpectedNodePaths' => ['a/a1']
            ],
            [
                'currentNodePaths' => ['/a/a4'],
                'subject' => '[instanceof Neos.ContentRepository.Testing:NodeType]',
                'expectedNodePaths' => [],
                'unexpectedNodePaths' => ['/a/a2','/a/a3','/a/a5']
            ],
            [
                'currentNodePaths' => ['/b/b4'],
                'subject' => 'b2[instanceof Neos.ContentRepository.Testing:NodeType]',
                'expectedNodePaths' => ['/b/b3'],
                'unexpectedNodePaths' => ['/b/b4','/a','/a/a1']
            ],
            [
                'currentNodePaths' => ['/a/a5'],
                'subject' => '',
                'expectedNodePaths' => ['/a/a1','/a/a2','/a/a3','/a/a4'],
                'unexpectedNodePaths' => ['/a/a5','/b','/b1']
            ]
        ];
    }

    /**
     * Tests on a tree:
     *
     * a
     *   a1 (testNodeType)
     *   a2
     *   a3 (testNodeType)
     *   a4
     *   a5
     * b
     *   b1
     *   b2 (testNodeType3)
     *   b3
     *   b4
     *
     * @test
     * @dataProvider prevUntilOperationDataProvider()
     */
    public function prevUntilOperationTests(array $currentNodePaths, $subject, array $expectedNodePaths, array $unexpectedNodePaths)
    {
        $nodeTypeManager = $this->objectManager->get(NodeTypeManager::class);
        $testNodeType = $nodeTypeManager->getNodeType('Neos.ContentRepository.Testing:NodeType');

        $rootNode = $this->node->getNode('/');
        $nodeA = $rootNode->createNode('a');
        $nodeA->createNode('a1', $testNodeType);
        $nodeA->createNode('a2');
        $nodeA->createNode('a3', $testNodeType);
        $nodeA->createNode('a4');
        $nodeA->createNode('a5');
        $nodeB = $rootNode->createNode('b');
        $nodeB->createNode('b1');
        $nodeB->createNode('b2', $testNodeType);
        $nodeB->createNode('b3');
        $nodeB->createNode('b4');


        $currentNodes = [];
        foreach ($currentNodePaths as $currentNodePath) {
            $currentNodes[] = $rootNode->getNode($currentNodePath);
        }

        if (is_array($subject)) {
            $subjectNodes = [];
            foreach ($subject as $subjectNodePath) {
                $subjectNodes[] = $rootNode->getNode($subjectNodePath);
            }
            $subject = $subjectNodes;
        }

        $q = new FlowQuery($currentNodes);
        $result = $q->prevUntil($subject)->get();

        if ($expectedNodePaths === [] && $unexpectedNodePaths === []) {
            self::assertEmpty($result);
        } else {
            foreach ($expectedNodePaths as $expectedNodePath) {
                $expectedNode = $rootNode->getNode($expectedNodePath);
                if (!in_array($expectedNode, $result)) {
                    $this->fail(sprintf('Expected result to contain node "%s"', $expectedNodePath));
                }
            }
            foreach ($unexpectedNodePaths as $unexpectedNodePath) {
                $unexpectedNode = $rootNode->getNode($unexpectedNodePath);
                if (in_array($unexpectedNode, $result)) {
                    $this->fail(sprintf('Expected result not to contain node "%s"', $unexpectedNodePath));
                }
            }
        }
    }
}
