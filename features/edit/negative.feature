Feature: Editing when there is nothing to change
  As a developer editing xphp
  I want no quick-fix or rename produced when there is nothing to act on

  Scenario: No code actions on a clean position
    Given the file at "/Clean.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $x = 1;
      """
    And the FQN index has been warmed on initialize
    When I request code actions on "1" at line 2 of "/Clean.xphp"
    Then no code actions are offered

  Scenario: Renaming a non-symbol position yields no edit
    Given the file at "/R.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $x = 1;
      """
    And the FQN index has been warmed on initialize
    When I rename "1" at line 2 of "/R.xphp" to "Foo"
    Then the response is null
