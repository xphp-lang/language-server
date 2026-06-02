Feature: Code actions
  As a developer editing xphp
  I want lightbulb quick-fixes for imports and diagnostics

  Background:
    Given the file at "/Models/User.xphp" contains the following lines:
      """
      <?php
      namespace App\Models;
      class User {}
      """
    And the file at "/Demos/Make.xphp" contains the following lines:
      """
      <?php
      namespace App\Demos;
      function make(): void { new User(); }
      """
    And the file at "/Unused.xphp" contains the following lines:
      """
      <?php
      namespace App;
      use App\Other\Unused;
      $x = 1;
      """
    And the file at "/Typo.xphp" contains the following lines:
      """
      <?php
      $x = nul;
      """
    And the FQN index has been warmed on initialize

  Scenario: Offer to import an unresolved class
    When I request code actions on "User" at line 2 of "/Demos/Make.xphp"
    Then a code action titled "Import App\Models\User" is offered

  Scenario: Offer to remove an unused import
    When I request code actions on "Unused" at line 2 of "/Unused.xphp"
    Then a code action titled "Optimize imports" is offered

  Scenario: Offer to fix an undefined-name typo
    When I request code actions for an undefined-name diagnostic on "nul" at line 1 of "/Typo.xphp"
    Then a code action titled 'Change to "null"' is offered
