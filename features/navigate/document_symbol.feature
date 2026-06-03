Feature: Document symbol outline
  As a developer editing xphp
  I want a hierarchical outline of the declarations in a file

  Background:
    Given the file at "/User.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class User {
          const ROLE = 'guest';
          public string $name = '';
          private int $age = 0;
          public function __construct(string $name) { $this->name = $name; }
          public function shout(): string { return ''; }
      }
      """
    And the FQN index has been warmed on initialize

  Scenario: Outline a class with its members
    When I request "textDocument/documentSymbol" for "/User.xphp"
    Then the outline contains a class "User" with 5 members
    And the class "User" has a constant member named "ROLE"
    And the class "User" has a property member named "$name"
    And the class "User" has a property member named "$age"
    And the class "User" has a constructor member named "__construct"
    And the class "User" has a method member named "shout"
    And the "User" selection range in "/User.xphp" covers "User"
