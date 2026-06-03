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

  Scenario: Show the signature and the first active parameter
    When I request signature help after "greet(" at line 1 of "/Use.xphp"
    Then the active signature label is "greet(string $name, int $count)"
    And the active parameter is 0

  Scenario: Advance the active parameter past a comma
    When I request signature help after "greet('a', " at line 1 of "/Use.xphp"
    Then the active parameter is 1
