@contentrepository @adapters=DoctrineDBAL
Feature: Filter - Property Value

  Background:
    Given using no content dimensions
    And using the following node types:
    """yaml
    'Neos.ContentRepository:Root':
      constraints:
        nodeTypes:
          'Neos.ContentRepository.Testing:Document': true
    'Neos.ContentRepository.Testing:Document':
      properties:
        text:
          type: string
    """
    And using identifier "default", I define a content repository
    And I am in content repository "default"

    And the command CreateRootWorkspace is executed with payload:
      | Key                  | Value                |
      | workspaceName        | "live"               |
      | newContentStreamId   | "cs-identifier"      |
    And I am in workspace "live"
    And the command CreateRootNodeAggregateWithNode is executed with payload:
      | Key             | Value                         |
      | nodeAggregateId | "lady-eleonode-rootford"      |
      | nodeTypeName    | "Neos.ContentRepository:Root" |
    # Node /name1 (has text value set)
    When the command CreateNodeAggregateWithNode is executed with payload:
      | Key                       | Value                                     |
      | nodeAggregateId           | "na-name1"                                |
      | nodeTypeName              | "Neos.ContentRepository.Testing:Document" |
      | nodeName                  | "name1"                                   |
      | originDimensionSpacePoint | {}                                        |
      | parentNodeAggregateId     | "lady-eleonode-rootford"                  |
      | initialPropertyValues     | {"text": "Original name1"}                |

    # Node /name2 (has text value2)
    When the command CreateNodeAggregateWithNode is executed with payload:
      | Key                       | Value                                     |
      | nodeAggregateId           | "na-name2"                                |
      | nodeTypeName              | "Neos.ContentRepository.Testing:Document" |
      | nodeName                  | "name2"                                   |
      | originDimensionSpacePoint | {}                                        |
      | parentNodeAggregateId     | "lady-eleonode-rootford"                  |
      | initialPropertyValues     | {"text": "value2"}                        |

      # no node name (has text value not set, and null will be ignored as unset)
    When the command CreateNodeAggregateWithNode is executed with payload:
      | Key                       | Value                                     |
      | nodeAggregateId           | "na-null-value"                           |
      | nodeTypeName              | "Neos.ContentRepository.Testing:Document" |
      | originDimensionSpacePoint | {}                                        |
      | parentNodeAggregateId     | "lady-eleonode-rootford"                  |
      | initialPropertyValues     | {"text": null}                            |

    # no node name (has text value not set)
    When the command CreateNodeAggregateWithNode is executed with payload:
      | Key                       | Value                                     |
      | nodeAggregateId           | "na-no-text"                              |
      | nodeTypeName              | "Neos.ContentRepository.Testing:Document" |
      | originDimensionSpacePoint | {}                                        |
      | parentNodeAggregateId     | "lady-eleonode-rootford"                  |
      | initialPropertyValues     | {}                                        |


  Scenario: PropertyValue
    When I run the following node migration for workspace "live", creating target workspace "migration-workspace" on contentStreamId "migration-cs", without publishing on success:
    """yaml
    migration:
      -
        filters:
          -
            type: 'PropertyValue'
            settings:
              propertyName: 'text'
              serializedValue: 'Original name1'
        transformations:
          -
            type: 'ChangePropertyValue'
            settings:
              property: 'text'
              newSerializedValue: 'fixed value'
    """
    # the original content stream has not been touched
    When I am in workspace "live" and dimension space point {}
    Then I expect a node identified by cs-identifier;na-name1;{} to exist in the content graph
    And I expect this node to have the following properties:
      | Key  | Value            |
      | text | "Original name1" |
    Then I expect a node identified by cs-identifier;na-name2;{} to exist in the content graph
    And I expect this node to have the following properties:
      | Key  | Value    |
      | text | "value2" |
    Then I expect a node identified by cs-identifier;na-null-value;{} to exist in the content graph
    And I expect this node to have no properties

    Then I expect a node identified by cs-identifier;na-no-text;{} to exist in the content graph
    And I expect this node to not have the property "text"

    # we filter based on the node name
    When I am in workspace "migration-workspace" and dimension space point {}
    Then I expect a node identified by migration-cs;na-name1;{} to exist in the content graph
    # only changed here
    And I expect this node to have the following properties:
      | Key  | Value         |
      | text | "fixed value" |
    Then I expect a node identified by migration-cs;na-name2;{} to exist in the content graph
    And I expect this node to have the following properties:
      | Key  | Value    |
      | text | "value2" |
    Then I expect a node identified by migration-cs;na-null-value;{} to exist in the content graph
    And I expect this node to have no properties

    Then I expect a node identified by migration-cs;na-no-text;{} to exist in the content graph
    And I expect this node to not have the property "text"

