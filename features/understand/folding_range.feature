Feature: Folding ranges
  As a developer editing xphp
  I want collapsible regions for class and method bodies

  Scenario: Fold the class body and each method body
    Given the file at "/Box.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Box<T>
      {
          public function __construct(public T $item)
          {
          }

          public function get(): T
          {
              return $this->item;
          }
      }
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/foldingRange" for "/Box.xphp"
    Then the response contains 3 folding ranges
    And a folding range spans lines 2 to 12

  Scenario: Single-line declarations are not folded
    Given the file at "/One.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Box<T> {}
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/foldingRange" for "/One.xphp"
    Then the response contains 0 folding ranges
