Feature: Signature help
  As a developer editing xphp
  I want the parameter list shown with the active argument highlighted

  Background:
    Given the file at "/lib.xphp" contains the following lines:
      """
      <?php
      function greet(string $name, int $count): string { return $name; }
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      greet('a', );
      """
    And the FQN index has been warmed on initialize

  Scenario: Show the full signature label
    When I request signature help after "greet(" at line 1 of "/Use.xphp"
    Then the active signature label is "greet(string $name, int $count)"

  Scenario Outline: The active parameter follows the cursor
    When I request signature help after "<after>" at line 1 of "/Use.xphp"
    Then the active parameter is <param>

    Examples:
      | after      | param |
      | greet(     | 0     |
      | greet('a', | 1     |
