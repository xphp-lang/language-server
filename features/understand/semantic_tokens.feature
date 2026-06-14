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
    And every semantic token is within the bounds of "/box.xphp"

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

  Scenario: Highlight every type argument of a turbofish call by its resolved kind
    Given the file at "/turbofish.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $m = new Map::<int, User>();
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/semanticTokens/full" for "/turbofish.xphp"
    Then a "type" token covers "int" in "/turbofish.xphp"
    And a "class" token covers "User" in "/turbofish.xphp"
    And a "operator" token covers "::" in "/turbofish.xphp"
    And a "operator" token covers "<" in "/turbofish.xphp"
    And a "operator" token covers ">" in "/turbofish.xphp"

  Scenario: Forward a type parameter through a turbofish inside a generic body
    Given the file at "/forward.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Box<T> {
          public function make(): mixed { return Inner::create::<T>(); }
      }
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/semanticTokens/full" for "/forward.xphp"
    Then a "typeParameter" token covers "T" in "/forward.xphp"

  Scenario: Multiline block comment highlights on every physical line
    Given the file at "/doc.xphp" contains the following lines:
      """
      <?php
      /**
       *
       */
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/semanticTokens/full" for "/doc.xphp"
    Then a "comment" token covers "/**" in "/doc.xphp"
    And a "comment" token covers " *" in "/doc.xphp"
    And a "comment" token covers " */" in "/doc.xphp"

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
