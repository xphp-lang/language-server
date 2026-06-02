Feature: Go to type definition
  As a developer editing xphp
  I want "Go to Type Definition" to jump to the class behind a variable's type

  Background:
    Given the file at "/User.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class User { public string $name = ''; }
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      use App\User;
      $user = new User();
      echo $user->name;
      """
    And the FQN index has been warmed on initialize

  Scenario: Jump from a variable use to the class of its inferred type
    When I request "textDocument/typeDefinition" on "$user" at line 3 of "/Use.xphp"
    Then the response points to "/User.xphp"
