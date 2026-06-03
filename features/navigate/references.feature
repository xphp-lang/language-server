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
    And a reference in "/User.xphp" covers "User"
    And a reference in "/Use1.xphp" covers "App\User"
    And a reference in "/Use1.xphp" covers "User"
    And a reference in "/Use2.xphp" covers "\App\User"

  Scenario: Find usages of a constructor includes "new" instantiation sites
    Given the file at "/Widget.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Widget {
          public function __construct(public string $id) {}
      }
      """
    And the file at "/WidgetUse.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $a = new Widget('a');
      $b = new Widget('b');
      """
    When I request "textDocument/references" on "__construct" at line 3 of "/Widget.xphp"
    Then the response contains 3 locations
    And a reference in "/WidgetUse.xphp" covers "Widget"
