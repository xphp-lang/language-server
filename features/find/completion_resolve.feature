Feature: Completion item resolve
  As a developer editing xphp
  I want a completion item's docblock fetched lazily when I focus it

  Background:
    Given the file at "/User.xphp" contains the following lines:
      """
      <?php
      namespace App;
      /**
       * A user account.
       */
      class User {}
      """
    And the FQN index has been warmed on initialize

  Scenario: Enrich a class item with its docblock
    When I resolve a class completion item for "App\User"
    Then the resolved item documentation contains "A user account."
