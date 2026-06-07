Feature: Completion
  As a developer editing xphp
  I want context-aware suggestions in type-argument positions

  Scenario: Suggest workspace classes in a type-argument position
    Given the file at "/Models.xphp" contains the following lines:
      """
      <?php
      namespace App\Models;
      class Plastic {}
      class Metal {}
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $x = new Box::<
      """
    And the FQN index has been warmed on initialize
    When I request completion after "Box::<" at line 2 of "/Use.xphp"
    Then a completion item labeled "Plastic" is offered
    And a completion item labeled "Metal" is offered
    And the completion item "Plastic" has kind "class"
    And the completion item "Plastic" has detail "App\Models\Plastic"

  Scenario: Insert the fully-qualified name when the class is not imported
    Given the file at "/Models.xphp" contains the following lines:
      """
      <?php
      namespace App\Models;
      class Plastic {}
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $x = new Box::<
      """
    And the FQN index has been warmed on initialize
    When I request completion after "Box::<" at line 2 of "/Use.xphp"
    Then the completion item "Plastic" inserts "\App\Models\Plastic"

  Scenario: Insert the short name when the class is already imported
    Given the file at "/Models.xphp" contains the following lines:
      """
      <?php
      namespace App\Models;
      class Plastic {}
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      namespace App;
      use App\Models\Plastic;
      $x = new Box::<
      """
    And the FQN index has been warmed on initialize
    When I request completion after "Box::<" at line 3 of "/Use.xphp"
    Then the completion item "Plastic" inserts "Plastic"

  Scenario Outline: Filter suggestions by the typed prefix
    Given the file at "/Models.xphp" contains the following lines:
      """
      <?php
      namespace App\Models;
      class Plastic {}
      class Metal {}
      class Wood {}
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $x = new Box::<<prefix>
      """
    And the FQN index has been warmed on initialize
    When I request completion after "Box::<<prefix>" at line 2 of "/Use.xphp"
    Then a completion item labeled "<match>" is offered
    And no completion item labeled "<other>" is offered

    Examples:
      | prefix | match   | other   |
      | Pla    | Plastic | Metal   |
      | Met    | Metal   | Plastic |
      | Woo    | Wood    | Plastic |

  Scenario: Filter suggestions by a generic bound
    Given the file at "/Box.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Box<T: \Stringable> {}
      """
    And the file at "/Models.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Tag implements \Stringable {
          public function __toString(): string { return ''; }
      }
      class Number {}
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $x = new Box::<
      """
    And the FQN index has been warmed on initialize
    When I request completion after "Box::<" at line 2 of "/Use.xphp"
    Then a completion item labeled "Tag" is offered
    And no completion item labeled "Number" is offered
