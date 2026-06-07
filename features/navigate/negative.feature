Feature: Navigation when there is nothing to find
  As a developer editing xphp
  I want navigation to return nothing rather than guess when no target exists

  Scenario: Go to definition of an undeclared class returns nothing
    Given the file at "/Use.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $x = new Missing::<int>();
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/definition" on "Missing" at line 2 of "/Use.xphp"
    Then the response is null

  Scenario: An interface with no implementers yields no locations
    Given the file at "/Speaker.xphp" contains the following lines:
      """
      <?php
      namespace App;
      interface Speaker {}
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/implementation" on "Speaker" at line 2 of "/Speaker.xphp"
    Then the response contains 0 locations

  Scenario: Workspace symbol search with no match is empty
    Given the file at "/Tag.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Tag {}
      """
    And the FQN index has been warmed on initialize
    When I search workspace symbols for "zzz"
    Then there are exactly 0 workspace symbols
