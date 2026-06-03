Feature: Rename symbol
  As a developer editing xphp
  I want renaming a class to rewrite its declaration and every reference

  Background:
    Given the file at "/User.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class User {}
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      namespace X;
      use App\User;
      $u = new User();
      """
    And the FQN index has been warmed on initialize

  Scenario: Rename a class and all of its references
    When I rename "User" at line 2 of "/User.xphp" to "Customer"
    Then the rename touches 2 files
    And the rename applies 3 edits
    And every rename edit inserts "Customer"
    And every rename edit covers "User"
