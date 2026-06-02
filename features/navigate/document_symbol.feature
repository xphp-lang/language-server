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
    Then the document outline contains a class named "User"
    And the document outline contains a constant named "ROLE"
    And the document outline contains a property named "$name"
    And the document outline contains a constructor named "__construct"
    And the document outline contains a method named "shout"
