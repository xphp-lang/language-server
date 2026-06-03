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

  Scenario: Outline a class with the right shape
    When I request "textDocument/documentSymbol" for "/User.xphp"
    Then the outline contains a class "User" with 5 members
    And the "User" selection range in "/User.xphp" covers "User"

  Scenario Outline: Each declared member appears nested in the outline
    When I request "textDocument/documentSymbol" for "/User.xphp"
    Then the class "User" has a <kind> member named "<member>"

    Examples:
      | kind        | member      |
      | constant    | ROLE        |
      | property    | $name       |
      | property    | $age        |
      | constructor | __construct |
      | method      | shout       |
