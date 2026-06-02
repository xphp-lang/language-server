Feature: Semantic tokens
  As a developer editing xphp
  I want AST-driven highlighting that distinguishes the generic type parameter

  Scenario: Emit tokens including the generic type parameter
    Given the file at "/box.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Box<T> {
          public T $item;
      }
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/semanticTokens/full" for "/box.xphp"
    Then the semantic tokens are non-empty
    And the semantic tokens include a "typeParameter" token
