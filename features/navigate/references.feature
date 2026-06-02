Feature: Find references
  As a developer editing xphp
  I want "Find Usages" to list every reference to a symbol across the project

  Background:
    Given the file at "/User.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class User {}
      """
    And the file at "/Use1.xphp" contains the following lines:
      """
      <?php
      namespace App\Other;
      use App\User;
      $u = new User();
      """
    And the file at "/Use2.xphp" contains the following lines:
      """
      <?php
      namespace App\Other;
      function f(\App\User $u): void {}
      """
    And the FQN index has been warmed on initialize

  Scenario: List the declaration, the import, and both usages
    When I request "textDocument/references" on "User" at line 2 of "/User.xphp"
    Then the response contains 4 locations
    And the response includes a location in "/User.xphp"
    And the response includes a location in "/Use1.xphp"
    And the response includes a location in "/Use2.xphp"
