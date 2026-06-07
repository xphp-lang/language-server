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
    And a "typeParameter" token covers "T" in "/box.xphp"

  Scenario: Highlight the type parameter of a generic closure
    Given the file at "/closure.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $make = function<T>() { return new T(); };
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/semanticTokens/full" for "/closure.xphp"
    Then a "typeParameter" token covers "T" in "/closure.xphp"

  Scenario: Highlight an interpolated variable inside a double-quoted string
    Given the file at "/Str.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $name = 'Ada';
      $greeting = "Hello $name world";
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/semanticTokens/full" for "/Str.xphp"
    Then a "variable" token covers "$name" in "/Str.xphp"
    And a "string" token covers "Hello " in "/Str.xphp"
