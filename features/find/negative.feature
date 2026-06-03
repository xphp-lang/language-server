Feature: Completion when there is nothing to suggest
  As a developer editing xphp
  I want no suggestions or enrichment when nothing matches

  Scenario: A prefix matching no class suggests none of them
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
      $x = new Box<Zzz
      """
    And the FQN index has been warmed on initialize
    When I request completion after "Box<Zzz" at line 2 of "/Use.xphp"
    Then no completion item labeled "Plastic" is offered
    And no completion item labeled "Metal" is offered

  Scenario: Resolving a class with no docblock adds no documentation
    Given the file at "/Plain.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Plain {}
      """
    And the FQN index has been warmed on initialize
    When I resolve a class completion item for "App\Plain"
    Then the resolved item has no documentation
