@contentrepository @adapters=DoctrineDBAL
Feature: Run projection integrity violation detection to find nodes that do not cover their origin dimension space point

  As a user of the CR I want to be able to detect whether there are nodes that are disconnected from the subgraph they originate in

  Background:
    Given using the following content dimensions:
      | Identifier | Values  | Generalizations |
      | language   | de, gsw | gsw->de         |
    And using the following node types:
    """yaml
    'Neos.ContentRepository.Testing:Document': []
    """
    And using identifier "default", I define a content repository
    And I am in content repository "default"
    And the command CreateRootWorkspace is executed with payload:
      | Key                  | Value                |
      | workspaceName        | "live"               |
      | newContentStreamId   | "cs-identifier"      |
    And I am in workspace "live" and dimension space point {"language":"de"}
    And the command CreateRootNodeAggregateWithNode is executed with payload:
      | Key             | Value                         |
      | nodeAggregateId | "lady-eleonode-rootford"      |
      | nodeTypeName    | "Neos.ContentRepository:Root" |

  Scenario: Create a node not covering its origin
    When the event NodeAggregateWithNodeWasCreated was published with payload:
      | Key                         | Value                                     |
      | workspaceName               | "live"                                    |
      | contentStreamId             | "cs-identifier"                           |
      | nodeAggregateId             | "sir-david-nodenborough"                  |
      | nodeTypeName                | "Neos.ContentRepository.Testing:Document" |
      | originDimensionSpacePoint   | {"language":"de"}                         |
      | coveredDimensionSpacePoints | [{"language":"de"},{"language":"gsw"}]    |
      | parentNodeAggregateId       | "lady-eleonode-rootford"                  |
      | nodeName                    | "document"                                |
      | nodeAggregateClassification | "regular"                                 |
    When the event NodeAggregateWithNodeWasCreated was published with payload:
      | Key                         | Value                                     |
      | workspaceName               | "live"                                    |
      | contentStreamId             | "cs-identifier"                           |
      | nodeAggregateId             | "nody-mc-nodeface"                        |
      | nodeTypeName                | "Neos.ContentRepository.Testing:Document" |
      | originDimensionSpacePoint   | {"language":"de"}                         |
      | coveredDimensionSpacePoints | [{"language":"gsw"}]                      |
      | parentNodeAggregateId       | "sir-david-nodenborough"                  |
      | nodeName                    | "document"                                |
      | nodeAggregateClassification | "regular"                                 |
    And I run integrity violation detection
    Then I expect the integrity violation detection result to contain exactly 1 errors
    And I expect integrity violation detection result error number 1 to have code 1597828607
